<?php
namespace Insiders\Collections\Rest;

use Insiders\Collections\Domain\DomainError;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\DbError;

defined( 'ABSPATH' ) || exit;

/**
 * Uniform errors (§19): 400 / 401 / 403 / 404 / 409 / 422 / 503 with
 * {code, message, field_errors, retryable, correlation_id} — no secrets.
 * Idempotency: same key + same body returns the stored result; same key with a
 * different body returns 409 and changes nothing (AT42).
 */
final class Http {

	public static function error( string $code, string $message, int $status, array $fields = array(), bool $retryable = false ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'           => $code,
				'message'        => $message,
				'field_errors'   => (object) $fields,
				'retryable'      => $retryable,
				'correlation_id' => Audit::correlation(),
			),
			$status
		);
	}

	/** Wraps a handler: domain errors -> uniform errors; unexpected -> 500 without internals. */
	public static function run( callable $fn ): \WP_REST_Response {
		try {
			$res = $fn();
			return $res instanceof \WP_REST_Response ? $res : new \WP_REST_Response( $res, 200 );
		} catch ( DomainError $e ) {
			return self::error( $e->error_code, $e->getMessage(), $e->status, $e->field_errors, $e->retryable );
		} catch ( DbError $e ) {
			error_log( '[ICOL] db error ' . Audit::correlation() . ': ' . $e->getMessage() );
			return self::error( $e->duplicate ? 'conflict' : 'db_error', $e->duplicate ? 'הרשומה כבר קיימת' : 'שגיאת מסד נתונים', $e->duplicate ? 409 : 500, array(), ! $e->duplicate );
		} catch ( \Throwable $e ) {
			error_log( '[ICOL] error ' . Audit::correlation() . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			return self::error( 'internal', 'שגיאה פנימית. מזהה לבירור: ' . Audit::correlation(), 500, array(), true );
		}
	}

	/** Mutation wrapper with Idempotency-Key. */
	public static function mutate( \WP_REST_Request $req, callable $fn ): \WP_REST_Response {
		$key = (string) $req->get_header( 'idempotency_key' );
		if ( '' === $key || strlen( $key ) > 128 ) {
			return self::error( 'idempotency_key_required', 'חסרה כותרת Idempotency-Key', 400 );
		}
		$uid   = get_current_user_id();
		$hkey  = hash( 'sha256', $uid . '|' . $key );
		$rhash = hash( 'sha256', $req->get_route() . '|' . wp_json_encode( $req->get_json_params() ?: $req->get_body_params() ) );
		$prev  = Db::row( 'SELECT * FROM ' . Db::t( 'idempotency' ) . ' WHERE idem_key = %s', $hkey );
		if ( $prev ) {
			if ( ! hash_equals( $prev['request_hash'], $rhash ) ) {
				return self::error( 'idempotency_mismatch', 'אותו מפתח פעולה נשלח עם תוכן שונה', 409 );
			}
			return new \WP_REST_Response( json_decode( (string) $prev['response'], true ), (int) $prev['status_code'] );
		}
		$res = self::run( $fn );
		if ( $res->get_status() < 500 ) {
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . Db::t( 'idempotency' ) . ' (idem_key, user_id, route, request_hash, status_code, response, created_at) VALUES (%s, %d, %s, %s, %d, %s, %s)',
					$hkey,
					$uid,
					mb_substr( $req->get_route(), 0, 190 ),
					$rhash,
					$res->get_status(),
					wp_json_encode( $res->get_data() ),
					Clock::utc()
				)
			);
		}
		return $res;
	}

	public static function int( \WP_REST_Request $r, string $k ): int {
		return (int) $r->get_param( $k );
	}

	public static function str( \WP_REST_Request $r, string $k ): string {
		$v = $r->get_param( $k );
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}
}
