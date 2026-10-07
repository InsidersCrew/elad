<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Outbound HTTP through the WordPress HTTP API, so tests intercept it with
 * pre_http_request and nothing bypasses the site's proxy/TLS settings.
 *
 * Classifies outcomes the way §20 needs them: a timeout on a WRITE is "unknown"
 * (the provider may have acted), never "failed" — retrying it blindly is how a
 * customer gets two messages or two charges.
 */
final class Http {

	public const OK        = 'ok';
	public const RETRYABLE = 'retryable';   // 429 / 5xx / network on a safe read
	public const AUTH      = 'auth';        // 401/403 — suspend the integration
	public const PERMANENT = 'permanent';   // 4xx business error — fix, don't retry
	public const UNKNOWN   = 'unknown';     // write that may or may not have happened

	public static function request( string $method, string $url, array $args = array(), bool $is_write = false ): array {
		$args = array_merge(
			array(
				'method'  => $method,
				'timeout' => 8, // Runner is invoked by cron; a long wait holds a PHP worker hostage.
				'headers' => array(),
			),
			$args
		);
		$started  = microtime( true );
		$response = wp_remote_request( $url, $args );
		$ms       = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'outcome' => $is_write ? self::UNKNOWN : self::RETRYABLE,
				'status'  => 0,
				'body'    => '',
				'json'    => null,
				'error'   => $response->get_error_message(),
				'ms'      => $ms,
				'retry_after' => null,
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );
		$retry  = wp_remote_retrieve_header( $response, 'retry-after' );

		if ( $status >= 200 && $status < 300 ) {
			$outcome = self::OK;
		} elseif ( 401 === $status || 403 === $status ) {
			$outcome = self::AUTH;
		} elseif ( 429 === $status || $status >= 500 ) {
			$outcome = $is_write && $status >= 500 ? self::UNKNOWN : self::RETRYABLE;
		} else {
			$outcome = self::PERMANENT;
		}
		return array(
			'outcome'     => $outcome,
			'status'      => $status,
			'body'        => $body,
			'json'        => is_array( $json ) ? $json : null,
			'error'       => self::OK === $outcome ? '' : mb_substr( $body, 0, 500 ),
			'ms'          => $ms,
			'retry_after' => '' !== (string) $retry ? (int) $retry : null,
		);
	}
}
