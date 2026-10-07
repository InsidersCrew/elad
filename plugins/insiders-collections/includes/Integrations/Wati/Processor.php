<?php
namespace Insiders\Collections\Integrations\Wati;

use Insiders\Collections\Domain\Customers;
use Insiders\Collections\Domain\Exceptions;
use Insiders\Collections\Domain\Messaging;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Engine\Inbox;
use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * WATI webhook events. Event names appear both with and without the "_v2"
 * suffix; failures arrive as templateMessageFailed. Outbound echoes of our own
 * messages are recognized by provider id and never treated as customer text.
 */
final class Processor {

	public static function process( array $ev ): string {
		$p    = Inbox::payload( $ev );
		$type = preg_replace( '/_v2$/', '', (string) ( $p['eventType'] ?? '' ) );
		switch ( $type ) {
			case 'message':
				return self::message( $p );
			case 'sessionMessageSent':
			case 'templateMessageSent':
				return self::status( $p, 'sent' ) ?? self::operator_echo( $p, $type );
			case 'sentMessageDELIVERED':
				return self::status( $p, 'delivered' ) ?? 'ignored';
			case 'sentMessageREAD':
				return self::status( $p, 'read' ) ?? 'ignored';
			case 'templateMessageFailed':
			case 'sentMessageFAILED':
				$r = self::status( $p, 'failed' );
				if ( $r ) {
					$mid = self::find_message( $p );
					Exceptions::open( 'delivery_failed:' . $mid, 'integration_failure', 'medium', 'WATI דיווח על כשל מסירה: ' . mb_substr( (string) ( $p['failedDetail'] ?? $p['failedCode'] ?? '' ), 0, 200 ), array( 'entity_type' => 'message', 'entity_id' => (int) $mid ) );
				}
				return $r ?? 'ignored';
			case 'sentMessageREPLIED':
				return 'ignored'; // the reply itself arrives as a "message" event
		}
		return 'ignored';
	}

	private static function find_message( array $p ): ?int {
		foreach ( array( 'localMessageId', 'id', 'whatsappMessageId' ) as $k ) {
			if ( ! empty( $p[ $k ] ) ) {
				$id = Db::value( 'SELECT id FROM ' . Db::t( 'messages' ) . " WHERE provider = 'wati' AND provider_id = %s", (string) $p[ $k ] );
				if ( $id ) {
					return (int) $id;
				}
			}
		}
		return null;
	}

	/** Status only moves forward: delivered never goes back to sent because events arrive out of order. */
	private static function status( array $p, string $to ): ?string {
		$id = self::find_message( $p );
		if ( ! $id ) {
			return null;
		}
		$rank = array( 'queued' => 0, 'accepted' => 1, 'unknown' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5 );
		$cur  = (string) Db::value( 'SELECT delivery_state FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $id );
		if ( 'failed' === $to || ( $rank[ $to ] ?? 0 ) > ( $rank[ $cur ] ?? 0 ) ) {
			Db::update( 'messages', array( 'delivery_state' => $to, 'updated_at' => Clock::utc() ), array( 'id' => $id ) );
		}
		if ( 'unknown' === $cur && 'failed' !== $to ) {
			// A status event resolves an "unknown" send: it did go out.
			Exceptions::resolve_by_key( 'send_unknown:cust:' . Db::value( 'SELECT customer_id FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $id ), 'אירוע סטטוס מ-WATI אישר שההודעה נשלחה' );
		}
		return 'processed';
	}

	private static function customers_for( array $p ): array {
		$wa = preg_replace( '/\D/', '', (string) ( $p['waId'] ?? '' ) );
		if ( '' === $wa ) {
			return array();
		}
		return Customers::by_phone( (string) Phone::e164( $wa ) );
	}

	private static function message( array $p ): string {
		if ( ! empty( $p['owner'] ) ) {
			// owner=true: sent from our side of the conversation (operator or bot).
			return self::operator_echo( $p, 'message' );
		}
		$customers = self::customers_for( $p );
		if ( ! $customers ) {
			Exceptions::open( 'unknown_sender:' . md5( (string) ( $p['waId'] ?? '' ) ), 'event_without_customer', 'low', 'הודעה ממספר שאינו מזוהה כלקוח', array() );
			return 'ignored';
		}
		if ( count( $customers ) > 1 ) {
			// AT07: a shared number pauses every case of every matching customer until identity is resolved.
			foreach ( $customers as $c ) {
				foreach ( Db::rows( 'SELECT id, workflow_state FROM ' . Db::t( 'cases' ) . " WHERE customer_id = %d AND workflow_state NOT IN ('closed','draft')", (int) $c['id'] ) as $case ) {
					Scheduler::cancel_for_case( (int) $case['id'], 'shared_number_inbound' );
					if ( Workflow::can( $case['workflow_state'], 'human_review' ) ) {
						Workflow::transition( (int) $case['id'], 'human_review', 'הודעה ממספר משותף לכמה לקוחות', null, array(), 'wati' );
					}
				}
			}
			Exceptions::open( 'shared_inbound:' . md5( (string) $p['waId'] ), 'identity_conflict', 'medium', 'הודעה נכנסת ממספר משותף ל־' . count( $customers ) . ' לקוחות', array() );
			return 'needs_match';
		}
		$ts = isset( $p['timestamp'] ) && is_numeric( $p['timestamp'] ) ? Clock::utc( (int) $p['timestamp'] ) : ( ! empty( $p['created'] ) ? Clock::utc( (int) strtotime( (string) $p['created'] ) ) : Clock::utc() );
		Messaging::handle_inbound(
			(int) $customers[0]['id'],
			array(
				'provider_id' => (string) ( $p['whatsappMessageId'] ?? $p['id'] ?? '' ) ?: null,
				'text'        => (string) ( $p['text'] ?? ( $p['buttonReply']['text'] ?? '' ) ),
				'type'        => (string) ( $p['type'] ?? 'text' ),
				'occurred_at' => $ts,
			)
		);
		return 'processed';
	}

	private static function operator_echo( array $p, string $type ): string {
		if ( self::find_message( $p ) ) {
			return 'processed'; // our own message
		}
		$customers = self::customers_for( $p );
		if ( 1 !== count( $customers ) ) {
			return 'ignored';
		}
		if ( 'templateMessageSent' === $type ) {
			return 'ignored'; // templates are only sent by us or by broadcasts outside this flow
		}
		Messaging::record_operator_outbound(
			(int) $customers[0]['id'],
			array(
				'provider_id' => (string) ( $p['whatsappMessageId'] ?? $p['id'] ?? '' ) ?: null,
				'text'        => (string) ( $p['text'] ?? '' ),
				'occurred_at' => Clock::utc(),
			)
		);
		return 'processed';
	}
}
