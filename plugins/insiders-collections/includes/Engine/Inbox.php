<?php
namespace Insiders\Collections\Engine;

use Insiders\Collections\Integrations\Tranzila\Processor as TranzilaProcessor;
use Insiders\Collections\Integrations\Wati\Processor as WatiProcessor;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Crypto;
use Insiders\Collections\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Durable inbox (§4 step 1, §15). The webhook handler only stores and answers 200;
 * processing happens in the runner. The delivery key (body hash or provider event
 * id) dedupes re-deliveries; business uniqueness (transaction id) is enforced
 * again downstream, because a changed payload is not a new payment.
 */
final class Inbox {

	/** @return array{0:int,1:bool} [event_id, duplicate] */
	public static function store( string $provider, string $integration_id, string $raw, string $content_type, array $parsed, string $event_key, ?string $occurred_at ): array {
		global $wpdb;
		$redacted = Crypto::redact_payload( $parsed );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Db::t( 'inbox_events' ) . ' (provider, integration_id, provider_event_key, payload_enc, payload_redacted, content_type, occurred_at, received_at, processing_state) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)',
				$provider,
				$integration_id,
				$event_key,
				Crypto::available() ? Crypto::encrypt( $raw ) : null,
				wp_json_encode( $redacted, JSON_UNESCAPED_UNICODE ),
				mb_substr( $content_type, 0, 100 ),
				$occurred_at,
				Clock::utc(),
				'received'
			)
		);
		if ( $wpdb->insert_id ) {
			return array( (int) $wpdb->insert_id, false );
		}
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( 'inbox insert failed: ' . $wpdb->last_error );
		}
		return array( (int) Db::value( 'SELECT id FROM ' . Db::t( 'inbox_events' ) . ' WHERE provider = %s AND provider_event_key = %s', $provider, $event_key ), true );
	}

	/** Original parsed payload: decrypted raw when available, otherwise the redacted copy. */
	public static function payload( array $event ): array {
		if ( ! empty( $event['payload_enc'] ) ) {
			$raw = Crypto::decrypt( $event['payload_enc'] );
			if ( null !== $raw ) {
				return self::parse( $raw, (string) $event['content_type'] );
			}
		}
		return (array) json_decode( (string) $event['payload_redacted'], true );
	}

	/** Accepts JSON and form-encoded bodies — Tranzila documents both shapes on different pages. */
	public static function parse( string $raw, string $content_type ): array {
		$trim = ltrim( $raw );
		if ( str_starts_with( $trim, '{' ) || str_starts_with( $trim, '[' ) || false !== stripos( $content_type, 'json' ) ) {
			$j = json_decode( $raw, true );
			if ( is_array( $j ) ) {
				return $j;
			}
		}
		$out = array();
		parse_str( $raw, $out );
		return is_array( $out ) ? $out : array();
	}

	public static function process( int $limit ): array {
		$rows = Db::rows( 'SELECT id FROM ' . Db::t( 'inbox_events' ) . " WHERE processing_state = 'received' OR (processing_state = 'processing' AND processed_at IS NULL AND received_at < %s) ORDER BY id LIMIT %d", Clock::utc( Clock::now() - 600 ), $limit );
		$out  = array();
		foreach ( $rows as $r ) {
			$n = Db::exec( 'UPDATE ' . Db::t( 'inbox_events' ) . " SET processing_state = 'processing', attempts = attempts + 1 WHERE id = %d AND processing_state IN ('received','processing')", (int) $r['id'] );
			if ( 1 !== $n ) {
				continue;
			}
			$ev = Db::row( 'SELECT * FROM ' . Db::t( 'inbox_events' ) . ' WHERE id = %d', (int) $r['id'] );
			try {
				$state = 'tranzila' === $ev['provider'] ? TranzilaProcessor::process( $ev ) : WatiProcessor::process( $ev );
				$err   = '';
			} catch ( \Throwable $e ) {
				$state = (int) $ev['attempts'] >= 5 ? 'failed' : 'received';
				$err   = mb_substr( $e->getMessage(), 0, 500 );
			}
			Db::update( 'inbox_events', array( 'processing_state' => $state, 'last_error' => $err ?: null, 'processed_at' => 'received' === $state ? null : Clock::utc() ), array( 'id' => (int) $ev['id'] ) );
			$out[ $state ] = ( $out[ $state ] ?? 0 ) + 1;
		}
		return $out;
	}

	/** Operator replay of a stored event (§20): shows what already exists before re-running. */
	public static function replay( int $id ): array {
		$ev = Db::row( 'SELECT * FROM ' . Db::t( 'inbox_events' ) . ' WHERE id = %d', $id );
		if ( ! $ev ) {
			return array( 'ok' => false, 'error' => 'not found' );
		}
		Db::update( 'inbox_events', array( 'processing_state' => 'received', 'processed_at' => null ), array( 'id' => $id ) );
		\Insiders\Collections\Support\Audit::log( 'inbox.replay', 'inbox_event', $id, array( 'state' => $ev['processing_state'] ), null, 'operator replay' );
		return array( 'ok' => true );
	}
}
