<?php
namespace Insiders\Collections\Rest;

use Insiders\Collections\Engine\Inbox;
use Insiders\Collections\Engine\Runner;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound endpoints. Contract (§15): basic limits -> durable store -> 200. No AI,
 * no provider call, no domain logic before answering — WATI retries a slow
 * endpoint and marks it defective after 100 consecutive failures.
 *
 * The path secret identifies the integration; it is not authentication of the
 * payload (Tranzila documents none for My Billing). Authenticity comes from the
 * verification read the processor performs.
 */
final class Webhooks {
	private const MAX_BYTES = 262144;

	public static function register(): void {
		register_rest_route(
			Api::NS,
			'/webhooks/tranzila/(?P<kind>sto|pr)/(?P<secret>[A-Za-z0-9_\-]{20,})',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static fn( \WP_REST_Request $r ) => self::tranzila( $r ),
			)
		);
		register_rest_route(
			Api::NS,
			'/webhooks/wati/(?P<secret>[A-Za-z0-9_\-]{20,})',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static fn( \WP_REST_Request $r ) => self::wati( $r ),
			)
		);
		register_rest_route(
			Api::NS,
			'/cron/tick',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( \WP_REST_Request $r ) {
					$key = (string) $r->get_param( 'key' );
					if ( '' === $key || ! hash_equals( Settings::secret( 'cron_key' ), $key ) ) {
						return new \WP_REST_Response( array( 'code' => 'forbidden' ), 403 );
					}
					return new \WP_REST_Response( Runner::tick( 'server-cron' ), 200 );
				},
			)
		);
	}

	private static function check_secret( string $name, string $given ): bool {
		$expected = Settings::secret( $name );
		return '' !== $expected && hash_equals( $expected, $given );
	}

	private static function tranzila( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! self::check_secret( 'tranzila_webhook_secret', (string) $r->get_param( 'secret' ) ) ) {
			return new \WP_REST_Response( array( 'code' => 'not_found' ), 404 );
		}
		$raw = (string) $r->get_body();
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new \WP_REST_Response( array( 'code' => 'too_large' ), 413 );
		}
		$ct     = (string) $r->get_header( 'content_type' );
		$parsed = Inbox::parse( $raw, $ct );
		if ( ! $parsed ) {
			return new \WP_REST_Response( array( 'code' => 'empty' ), 400 );
		}
		try {
			// Delivery key = body hash: identical re-deliveries collapse; business uniqueness is enforced downstream.
			Inbox::store( 'tranzila', (string) $r->get_param( 'kind' ), $raw, $ct, $parsed, hash( 'sha256', $raw ), null );
		} catch ( \Throwable $e ) {
			error_log( '[ICOL] tranzila inbox store failed: ' . $e->getMessage() );
			return new \WP_REST_Response( array( 'code' => 'store_failed' ), 503 );
		}
		Runner::beat( 'webhook_tranzila', 'success' );
		// Tranzila's Notify convention expects a plain "OK".
		$res = new \WP_REST_Response( 'OK', 200 );
		return $res;
	}

	private static function wati( \WP_REST_Request $r ): \WP_REST_Response {
		if ( ! self::check_secret( 'wati_webhook_secret', (string) $r->get_param( 'secret' ) ) ) {
			return new \WP_REST_Response( array( 'code' => 'not_found' ), 404 );
		}
		$raw = (string) $r->get_body();
		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new \WP_REST_Response( array( 'code' => 'too_large' ), 413 );
		}
		$parsed = Inbox::parse( $raw, 'application/json' );
		if ( ! $parsed ) {
			return new \WP_REST_Response( array( 'code' => 'empty' ), 400 );
		}
		$event = (string) ( $parsed['eventType'] ?? '' );
		$id    = (string) ( $parsed['id'] ?? $parsed['whatsappMessageId'] ?? $parsed['localMessageId'] ?? '' );
		$key   = '' !== $id ? hash( 'sha256', $event . '|' . $id ) : hash( 'sha256', $raw );
		try {
			Inbox::store( 'wati', 'wati', $raw, 'application/json', $parsed, $key, isset( $parsed['timestamp'] ) && is_numeric( $parsed['timestamp'] ) ? Clock::utc( (int) $parsed['timestamp'] ) : null );
		} catch ( \Throwable $e ) {
			error_log( '[ICOL] wati inbox store failed: ' . $e->getMessage() );
			return new \WP_REST_Response( array( 'code' => 'store_failed' ), 503 );
		}
		Runner::beat( 'webhook_wati', 'success' );
		// "Stop reminders immediately on reply" (§15): process customer messages right away when cheap.
		if ( 'message' === $event && empty( $parsed['owner'] ) ) {
			add_action( 'shutdown', static fn() => Runner::tick( 'wati-inbound' ) );
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
