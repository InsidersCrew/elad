<?php
namespace Insiders\Collections\Integrations\Wati;

use Insiders\Collections\Domain\Customers;
use Insiders\Collections\Domain\SendGuard;
use Insiders\Collections\Domain\Templates;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Http;
use Insiders\Collections\Support\Phone;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WATI v1 endpoints (verified 2026-10): sendTemplateMessage, sendSessionMessage,
 * updateContactAttributes. WATI assigns its own localMessageId (a custom id
 * cannot be passed), so we store the one it returns and match status events on it.
 *
 * HTTP 200 is "accepted", never "delivered" (AT30): delivery comes only from
 * status webhooks. Webhooks are unsigned and retried up to 144 times, so the
 * receiver stores durably and dedupes.
 */
final class Client {

	public static function configured(): bool {
		return '' !== (string) Settings::get( 'wati_api_base' ) && Settings::has_secret( 'wati_token' );
	}

	private static function call( string $method, string $path, array $query, ?array $body, bool $is_write ): array {
		$url = rtrim( (string) Settings::get( 'wati_api_base' ), '/' ) . $path;
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}
		return Http::request(
			$method,
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . preg_replace( '/^Bearer\s+/i', '', Settings::secret( 'wati_token' ) ),
					'Content-Type'  => 'application/json',
				),
				'body'    => null === $body ? null : wp_json_encode( $body ),
			),
			$is_write
		);
	}

	/**
	 * The cheap "is this still right?" conditions just before a message goes, for WhatsApp and for the
	 * email a student without WhatsApp gets. Empty when it may go, otherwise why it is voided.
	 */
	public static function void_reason( array $m, ?array $case, string $channel ): string {
		$void     = '';
		if ( Settings::on( 'kill_switch' ) || Settings::on( 'display_only' ) ) {
			$void = 'kill_switch';
		} elseif ( ! \Insiders\Collections\Domain\SendGuard::allowed( (int) $m['customer_id'] ) ) {
			$void = 'not_in_allowlist';
		} elseif ( false === Customers::permission( (int) $m['customer_id'], $channel ) ) {
			$void = 'opted_out';
		} elseif ( 'commitment_reminder' === $m['kind'] && ( Clock::ts( $m['created_at'] ) ?? 0 ) < Clock::now() - 3 * HOUR_IN_SECONDS ) {
			$void = 'stale'; // queued hours ago (WATI was down): planned again from the current date and window
		} elseif ( in_array( $m['kind'], array( 'collection_reminder', 'commitment_reminder' ), true ) && $case && ! in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			$void = 'state:' . $case['workflow_state'];
		} elseif ( in_array( $m['kind'], array( 'collection_reminder', 'commitment_reminder' ), true ) && ( $case && 'commitment' === ( $case['phase'] ?? 'charge' )
			? ( ! empty( $case['last_inbound_at'] ) && $case['last_inbound_at'] > $m['created_at'] ) // a reply the case took as one
			: (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'in' AND occurred_at > %s", (int) $m['customer_id'], $m['created_at'] ) ) ) {
			$void = 'customer_replied'; // AT12
		}
		return $void;
	}

	/** WATI's words for a number that has no WhatsApp account (not a general "undeliverable"). */
	public static function says_no_whatsapp( string $text ): bool {
		return (bool) preg_match( '/not\s+(a\s+)?(valid\s+)?whats\s?app|not\s+on\s+whats\s?app|isn.?t\s+on\s+whats\s?app|invalid\s+whats\s?app|no\s+whats\s?app\s+account|אינו מחובר לוואטסאפ|לא מחובר לוואטסאפ/iu', $text );
	}

	/** Outbox handler. Re-checks the cheap "is this still right?" conditions just before sending. */
	public static function deliver_message( int $message_id ): array {
		$m = Db::row( 'SELECT * FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', $message_id );
		if ( ! $m || 'queued' !== $m['delivery_state'] ) {
			return array( 'outcome' => Http::OK, 'result' => array( 'skipped' => $m['delivery_state'] ?? 'missing' ) );
		}
		$customer = Customers::get( (int) $m['customer_id'] );
		$case     = $m['case_id'] ? Workflow::get( (int) $m['case_id'] ) : null;
		$void     = self::void_reason( $m, $case, 'whatsapp' );
		if ( '' !== $void ) {
			Db::update( 'messages', array( 'delivery_state' => 'cancelled', 'updated_at' => Clock::utc() ), array( 'id' => $message_id ) );
			\Insiders\Collections\Domain\Journey::on_send_failed( $message_id, false ); // a journey step that did not go is not taken
			return array( 'outcome' => Http::OK, 'result' => array( 'voided' => $void ) );
		}
		$wa  = Phone::wa_id( (string) $customer['phone_e164'] );
		$tpl = Templates::get( (string) $m['template_key'] );
		if ( $tpl && 'whatsapp_template' === $tpl['channel'] ) {
			$vars   = self::template_params( $m, $tpl );
			$body   = array(
				'template_name'  => (string) $tpl['provider_name'],
				'broadcast_name' => 'insiders_payment_update_' . gmdate( 'Ymd' ),
				'parameters'     => $vars,
			);
			if ( '' !== (string) Settings::get( 'wati_channel_number' ) ) {
				$body['channel_number'] = (string) Settings::get( 'wati_channel_number' );
			}
			$r = self::call( 'POST', '/api/v1/sendTemplateMessage', array( 'whatsappNumber' => $wa ), $body, true );
		} else {
			if ( ! SendGuard::in_service_window( (int) $m['customer_id'] ) ) {
				// AT31: outside the 24h window a free-text reply is not allowed.
				Db::update( 'messages', array( 'delivery_state' => 'cancelled', 'updated_at' => Clock::utc() ), array( 'id' => $message_id ) );
				return array( 'outcome' => Http::OK, 'result' => array( 'voided' => 'service_window_closed' ) );
			}
			$r = self::call( 'POST', '/api/v1/sendSessionMessage/' . rawurlencode( $wa ), array( 'messageText' => (string) $m['body'] ), null, true );
		}
		if ( Http::OK === $r['outcome'] && is_array( $r['json'] ) && isset( $r['json']['result'] ) && false === $r['json']['result'] ) {
			$r['outcome'] = Http::PERMANENT;
			$r['error']   = (string) ( $r['json']['info'] ?? $r['json']['message'] ?? 'result=false' );
		}
		$valid = is_array( $r['json'] ) ? ( $r['json']['validWhatsAppNumber'] ?? $r['json']['receivers'][0]['isValidWhatsAppNumber'] ?? null ) : null;
		if ( Http::OK === $r['outcome'] && false === $valid ) {
			$r['outcome'] = Http::PERMANENT;
			$r['error']   = 'המספר אינו מחובר לוואטסאפ';
		}
		$state = array(
			Http::OK        => 'accepted',
			Http::UNKNOWN   => 'unknown',
			Http::RETRYABLE => 'queued',
			Http::AUTH      => 'failed',
			Http::PERMANENT => 'failed',
		)[ $r['outcome'] ];
		$provider_id = self::extract_id( (array) $r['json'] );
		Db::update(
			'messages',
			array_filter(
				array(
					'delivery_state' => $state,
					'provider_id'    => $provider_id,
					'updated_at'     => Clock::utc(),
				),
				static fn( $v ) => null !== $v
			),
			array( 'id' => $message_id )
		);
		if ( 'failed' === $state ) {
			// "Not on WhatsApp": the student moves to email and this step goes again there, no strike.
			$no_wa = false === $valid || ( Http::PERMANENT === $r['outcome'] && self::says_no_whatsapp( (string) ( $r['error'] ?? '' ) ) );
			$moved = $no_wa && Customers::mark_no_whatsapp( (int) $m['customer_id'], 'WATI: ' . (string) ( $r['error'] ?? '' ) ) && 'email' === Customers::channel( (int) $m['customer_id'] );
			if ( ! $moved ) {
				\Insiders\Collections\Domain\Exceptions::open( 'send_failed:' . $message_id, 'integration_failure', 'medium', 'שליחת הודעה נכשלה, אין שליחה חוזרת עיוורת', array( 'entity_type' => 'message', 'entity_id' => $message_id, 'customer_id' => (int) $m['customer_id'] ) );
			}
			// A refused token is the account's problem, not this student's number: no strike.
			\Insiders\Collections\Domain\Journey::on_send_failed( $message_id, ! $moved && Http::PERMANENT === $r['outcome'] );
		}
		return array( 'outcome' => $r['outcome'], 'error' => $r['error'] ?? '', 'retry_after' => $r['retry_after'] ?? null, 'result' => array( 'provider_id' => $provider_id ) );
	}

	/**
	 * WATI parameters [{name, value}] for the variables the body names (by name, so order is free),
	 * from the values stored at render time — what the preview showed is what is sent.
	 */
	private static function template_params( array $m, array $tpl ): array {
		$vars = (array) json_decode( (string) $m['vars_json'], true );
		$out  = array();
		foreach ( Templates::vars_in( (string) $tpl['body'] ) as $name ) {
			$out[] = array( 'name' => $name, 'value' => (string) ( $vars[ $name ] ?? '' ) );
		}
		return $out;
	}

	private static function extract_id( array $j ): ?string {
		$candidates = array(
			$j['receivers'][0]['localMessageId'] ?? null,
			$j['localMessageId'] ?? null,
			$j['message']['id'] ?? null,
			$j['model']['ids'][0] ?? null,
			$j['id'] ?? null,
		);
		foreach ( $candidates as $c ) {
			if ( is_scalar( $c ) && '' !== (string) $c ) {
				return mb_substr( (string) $c, 0, 128 );
			}
		}
		return null;
	}

	/** Conversation ownership flag shared with the onboarding agent (§17). */
	public static function set_owner_attribute( int $customer_id, string $owner ): array {
		$c = Customers::get( $customer_id );
		if ( ! $c || empty( $c['phone_e164'] ) ) {
			return array( 'outcome' => Http::PERMANENT, 'error' => 'no phone' );
		}
		if ( 'simulate' === \Insiders\Collections\Domain\SendGuard::mode( $customer_id ) ) {
			// Display-only writes nothing to WATI: the owner attribute silences the registration agent
			// for this contact, which is a real effect. Recorded as what would have been written.
			return array( 'outcome' => Http::OK, 'error' => '', 'result' => array( 'simulated' => true, 'attribute' => (string) Settings::get( 'wati_conversation_attr' ), 'value' => $owner ) );
		}
		$r = self::call( 'POST', '/api/v1/updateContactAttributes/' . rawurlencode( Phone::wa_id( $c['phone_e164'] ) ), array(), array( 'customParams' => array( array( 'name' => (string) Settings::get( 'wati_conversation_attr' ), 'value' => $owner ) ) ), true );
		return array( 'outcome' => Http::UNKNOWN === $r['outcome'] ? Http::RETRYABLE : $r['outcome'], 'error' => $r['error'] );
	}

	/**
	 * The name of the WATI contact with this number, as the team saved it. Read only. Only a contact whose
	 * number is exactly this one counts: if WATI ignored the filter, another contact's name is never taken.
	 *
	 * @return array{ok:bool, found:bool, name:string, error?:string}
	 */
	public static function contact_name( string $e164 ): array {
		$wa = Phone::wa_id( $e164 );
		if ( '' === $wa ) {
			return array( 'ok' => true, 'found' => false, 'name' => '' );
		}
		$r = self::call( 'GET', '/api/v1/getContacts', array( 'pageSize' => '5', 'pageNumber' => '1', 'attribute' => wp_json_encode( array( array( 'name' => 'phone', 'operator' => 'contain', 'value' => $wa ) ) ) ), null, false );
		if ( Http::OK !== $r['outcome'] || ! is_array( $r['json'] ) ) {
			return array( 'ok' => false, 'found' => false, 'name' => '', 'error' => (string) ( $r['error'] ?? 'no json' ) );
		}
		foreach ( (array) ( $r['json']['contact_list'] ?? $r['json']['contacts'] ?? array() ) as $c ) {
			$num = preg_replace( '/\D/', '', (string) ( $c['wAid'] ?? $c['phone'] ?? '' ) );
			if ( $num === $wa ) {
				$name = trim( (string) ( $c['fullName'] ?? $c['name'] ?? $c['firstName'] ?? '' ) );
				return array( 'ok' => true, 'found' => true, 'name' => $name );
			}
		}
		return array( 'ok' => true, 'found' => false, 'name' => '' );
	}

	/** Harmless read used by the health page. */
	public static function ping(): array {
		$r = self::call( 'GET', '/api/v1/getContacts', array( 'pageSize' => '1', 'pageNumber' => '1' ), null, false );
		return array( 'ok' => Http::OK === $r['outcome'], 'status' => $r['status'], 'error' => $r['error'], 'ms' => $r['ms'] );
	}
}
