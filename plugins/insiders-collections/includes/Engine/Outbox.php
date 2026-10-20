<?php
namespace Insiders\Collections\Engine;

use Insiders\Collections\Domain\Exceptions;
use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Integrations\Mailer;
use Insiders\Collections\Integrations\Pipedrive\Client as Pipedrive;
use Insiders\Collections\Integrations\Wati\Client as Wati;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Outbox (§15): external effects are written in the same transaction as the state
 * change and executed afterwards. Outcomes are kept separately; "unknown" is a
 * terminal state that needs a person or a provider lookup — never a blind retry.
 */
final class Outbox {
	private const MAX_ATTEMPTS = 5;
	private const LEASE_MINUTES = 15;

	public static function enqueue( string $type, string $agg_type, int $agg_id, string $key, array $payload ): int {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Db::t( 'outbox_events' ) . ' (event_type, aggregate_type, aggregate_id, idempotency_key, payload, state, next_attempt_at, created_at, updated_at) VALUES (%s, %s, %d, %s, %s, %s, %s, %s, %s)',
				$type,
				$agg_type,
				$agg_id,
				$key,
				wp_json_encode( $payload ),
				'pending',
				Clock::utc(),
				Clock::utc(),
				Clock::utc()
			)
		);
		return (int) ( $wpdb->insert_id ?: Db::value( 'SELECT id FROM ' . Db::t( 'outbox_events' ) . ' WHERE idempotency_key = %s', $key ) );
	}

	public static function process( int $limit ): array {
		$now  = Clock::utc();
		$out  = array( 'done' => 0, 'retry' => 0, 'failed' => 0, 'unknown' => 0 );
		// A run that died between "processing" and its outcome (timeout, fatal, restart) leaves the row
		// claimed. The call may or may not have reached the provider: unknown, for a person, never a resend.
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'outbox_events' ) . " WHERE state = 'processing' AND updated_at < %s ORDER BY id LIMIT 20", Clock::utc( Clock::now() - self::LEASE_MINUTES * MINUTE_IN_SECONDS ) ) as $ev ) {
			if ( in_array( $ev['event_type'], array( 'wati_send', 'pipedrive_activity' ), true ) ) {
				$out[ self::settle( $ev, array( 'outcome' => Http::UNKNOWN, 'error' => 'הריצה נקטעה באמצע הפעולה' ) ) ]++;
			} else {
				// Repeating these does no harm (an owner attribute, an alert, a suggestion): simply again.
				Db::update( 'outbox_events', array( 'state' => 'pending', 'next_attempt_at' => Clock::utc(), 'updated_at' => Clock::utc() ), array( 'id' => (int) $ev['id'] ) );
			}
		}
		$rows = Db::rows( 'SELECT id FROM ' . Db::t( 'outbox_events' ) . " WHERE state = 'pending' AND next_attempt_at <= %s ORDER BY id LIMIT %d", $now, $limit );
		foreach ( $rows as $r ) {
			$ev = Db::row( 'SELECT event_type FROM ' . Db::t( 'outbox_events' ) . ' WHERE id = %d', (int) $r['id'] );
			if ( str_starts_with( (string) ( $ev['event_type'] ?? '' ), 'wati' ) && Runner::integration_suspended( 'wati' ) ) {
				// WATI refused our token: the rest of the batch waits for the fix instead of failing one by one.
				Db::update( 'outbox_events', array( 'next_attempt_at' => Clock::utc( Clock::now() + 15 * MINUTE_IN_SECONDS ), 'updated_at' => Clock::utc() ), array( 'id' => (int) $r['id'] ) );
				continue;
			}
			$n = Db::exec( 'UPDATE ' . Db::t( 'outbox_events' ) . " SET state = 'processing', attempts = attempts + 1, updated_at = %s WHERE id = %d AND state = 'pending'", Clock::utc(), (int) $r['id'] );
			if ( 1 !== $n ) {
				continue;
			}
			$ev = Db::row( 'SELECT * FROM ' . Db::t( 'outbox_events' ) . ' WHERE id = %d', (int) $r['id'] );
			try {
				$res = self::dispatch( $ev );
			} catch ( \Throwable $e ) {
				$res = array( 'outcome' => Http::RETRYABLE, 'error' => $e->getMessage() );
			}
			$out[ self::settle( $ev, $res ) ]++;
		}
		return $out;
	}

	private static function dispatch( array $ev ): array {
		$payload = (array) json_decode( (string) $ev['payload'], true );
		switch ( $ev['event_type'] ) {
			case 'wati_send':
				return Wati::deliver_message( (int) $payload['message_id'] );
			case 'pipedrive_activity':
				return Pipedrive::push_task( (int) $payload['task_id'] );
			case 'email_alert':
				return Mailer::task_alert( (int) $payload['task_id'] );
			case 'wati_owner':
				return Wati::set_owner_attribute( (int) $payload['customer_id'], (string) $payload['owner'] );
			case 'ai_suggest':
				return \Insiders\Collections\Integrations\Ai\Classifier::suggest( (int) $payload['case_id'], (int) $payload['message_id'] );
		}
		return array( 'outcome' => Http::PERMANENT, 'error' => 'unknown event type' );
	}

	private static function settle( array $ev, array $res ): string {
		$outcome = $res['outcome'] ?? Http::PERMANENT;
		$base    = array( 'last_error' => mb_substr( (string) ( $res['error'] ?? '' ), 0, 500 ), 'result' => isset( $res['result'] ) ? wp_json_encode( $res['result'] ) : null, 'updated_at' => Clock::utc() );
		if ( Http::OK === $outcome ) {
			Db::update( 'outbox_events', array_merge( $base, array( 'state' => 'done' ) ), array( 'id' => (int) $ev['id'] ) );
			return 'done';
		}
		if ( Http::UNKNOWN === $outcome ) {
			Db::update( 'outbox_events', array_merge( $base, array( 'state' => 'unknown' ) ), array( 'id' => (int) $ev['id'] ) );
			self::message_outcome( $ev, 'unknown' );
			Exceptions::open( 'outbox_unknown:' . $ev['id'], 'send_unknown', 'high', 'פעולה חיצונית הסתיימה בתוצאה לא ידועה (' . $ev['event_type'] . ')' . ( 'wati_send' === $ev['event_type'] ? '. לבדוק בשיחה ב-WATI אם ההודעה הגיעה; סגירת החריג מסמנת אותה כנשלחה ומשחררת את הלקוח' : '' ), array( 'entity_type' => $ev['aggregate_type'], 'entity_id' => (int) $ev['aggregate_id'] ) );
			return 'unknown';
		}
		if ( Http::AUTH === $outcome ) {
			$integration = str_starts_with( $ev['event_type'], 'wati' ) ? 'wati' : ( str_starts_with( $ev['event_type'], 'pipedrive' ) ? 'pipedrive' : 'mail' );
			Runner::suspend( $integration, 'auth: ' . ( $res['error'] ?? '' ) );
			Db::update( 'outbox_events', array_merge( $base, array( 'state' => 'failed' ) ), array( 'id' => (int) $ev['id'] ) );
			self::message_outcome( $ev, 'failed' );
			return 'failed';
		}
		if ( Http::RETRYABLE === $outcome && (int) $ev['attempts'] < self::MAX_ATTEMPTS ) {
			$delay = isset( $res['retry_after'] ) && $res['retry_after'] ? (int) $res['retry_after'] : (int) ( 60 * ( 2 ** ( (int) $ev['attempts'] - 1 ) ) + random_int( 0, 30 ) );
			Db::update( 'outbox_events', array_merge( $base, array( 'state' => 'pending', 'next_attempt_at' => Clock::utc( Clock::now() + $delay ) ) ), array( 'id' => (int) $ev['id'] ) );
			return 'retry';
		}
		Db::update( 'outbox_events', array_merge( $base, array( 'state' => 'failed' ) ), array( 'id' => (int) $ev['id'] ) );
		self::message_outcome( $ev, 'failed' );
		Exceptions::open( 'outbox_failed:' . $ev['id'], 'integration_failure', 'medium', 'פעולה חיצונית נכשלה (' . $ev['event_type'] . '): ' . mb_substr( (string) ( $res['error'] ?? '' ), 0, 200 ), array( 'entity_type' => $ev['aggregate_type'], 'entity_id' => (int) $ev['aggregate_id'] ) );
		return 'failed';
	}

	/**
	 * A WhatsApp message whose send ended without reaching WATI's answer stays "queued" otherwise:
	 * it would look pending forever. Failed gives the journey step back; unknown blocks further sends.
	 */
	private static function message_outcome( array $ev, string $state ): void {
		if ( 'wati_send' !== $ev['event_type'] ) {
			return;
		}
		$id = (int) ( json_decode( (string) $ev['payload'], true )['message_id'] ?? 0 );
		if ( ! $id || 1 !== Db::exec( 'UPDATE ' . Db::t( 'messages' ) . " SET delivery_state = %s, updated_at = %s WHERE id = %d AND delivery_state = 'queued'", $state, Clock::utc(), $id ) ) {
			return;
		}
		if ( 'failed' === $state ) {
			\Insiders\Collections\Domain\Journey::on_send_failed( $id, false ); // an outage that outlasted the retries: no strike
		}
	}
}
