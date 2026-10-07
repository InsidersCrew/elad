<?php
namespace Insiders\Collections\Rest;

use Insiders\Collections\Domain\DomainError;
use Insiders\Collections\Security\Gate;

defined( 'ABSPATH' ) || exit;

/**
 * Routes of the scan gate.
 *  - desktop: a logged-in user with icol_enter (the lock screen itself);
 *  - phone:   public, authenticated by the one-time challenge id + the passkey signature,
 *             rate limited per IP. The phone is usually not logged in to WordPress at all;
 *  - manage:  devices and sessions, only from an unlocked session.
 */
final class GateApi {

	public static function register(): void {
		$ns    = Api::NS;
		$cid   = '(?P<cid>[a-f0-9]{32})';
		$id    = '(?P<id>\d+)';
		$desk  = static fn() => is_user_logged_in() && current_user_can( 'icol_enter' );
		$open  = static fn() => is_user_logged_in() && current_user_can( 'icol_enter' ) && Gate::unlocked();
		$phone = static function () {
			return self::rate_ok() ? true : new \WP_Error( 'rate_limited', 'יותר מדי ניסיונות. יש לנסות שוב בעוד כמה דקות.', array( 'status' => 429 ) );
		};
		$route = static function ( string $method, string $path, callable $perm, callable $fn ) use ( $ns ) {
			register_rest_route(
				$ns,
				$path,
				array(
					'methods'             => $method,
					'permission_callback' => $perm,
					'callback'            => static function ( \WP_REST_Request $q ) use ( $fn ) {
						$res = Http::run( static fn() => $fn( $q ) );
						$res->header( 'Cache-Control', 'no-store' );
						return $res;
					},
				)
			);
		};

		$route( 'GET', '/gate/state', $desk, static fn() => Gate::state() );
		$route( 'POST', '/gate/unlock', $desk, static fn() => Gate::start_unlock() );
		$route( 'GET', "/gate/unlock/$cid", $desk, static fn( $q ) => Gate::poll( (string) $q['cid'] ) );
		$route( 'POST', '/gate/pair', $desk, static fn( $q ) => Gate::start_pair( (string) $q->get_param( 'password' ) ) );
		$route( 'POST', "/gate/pair/$cid/confirm", $desk, static fn( $q ) => Gate::confirm_pair( (string) $q['cid'], (string) $q->get_param( 'code' ) ) );
		$route( 'GET', "/gate/pair/$cid", $desk, static fn( $q ) => Gate::poll( (string) $q['cid'] ) );
		$route(
			'POST',
			'/gate/lock',
			$desk,
			static function () {
				Gate::lock();
				return array( 'ok' => true );
			}
		);

		$route( 'POST', "/gate/phone/$cid/options", $phone, static fn( $q ) => Gate::phone_options( (string) $q['cid'] ) );
		$route( 'POST', "/gate/phone/$cid/approve", $phone, static fn( $q ) => Gate::phone_approve( (string) $q['cid'], (int) $q->get_param( 'number' ), self::credential( $q ) ) );
		$route( 'POST', "/gate/phone/$cid/register", $phone, static fn( $q ) => Gate::phone_register( (string) $q['cid'], (string) $q->get_param( 'label' ), self::credential( $q ) ) );

		$route( 'GET', '/gate/devices', $open, static fn() => Gate::list_devices() );
		$route(
			'POST',
			"/gate/devices/$id/approve",
			$open,
			static function ( $q ) {
				Gate::approve_device( (int) $q['id'] );
				return Gate::list_devices();
			}
		);
		$route(
			'POST',
			"/gate/devices/$id/revoke",
			$open,
			static function ( $q ) {
				Gate::revoke_device( (int) $q['id'] );
				return Gate::list_devices();
			}
		);
		$route( 'GET', '/gate/sessions', $open, static fn() => Gate::list_sessions() );
		$route(
			'POST',
			"/gate/sessions/$id/revoke",
			$open,
			static function ( $q ) {
				Gate::revoke_session( (int) $q['id'] );
				return Gate::list_sessions();
			}
		);
		$route( 'POST', '/gate/settings', $open, static fn( $q ) => Gate::save_settings( (array) $q->get_json_params() ) );
	}

	private static function credential( \WP_REST_Request $q ): array {
		$c = $q->get_param( 'credential' );
		if ( ! is_array( $c ) || ! isset( $c['response'] ) || ! is_array( $c['response'] ) ) {
			throw new DomainError( 'validation_failed', 'חסרים נתוני האימות מהטלפון', 400 );
		}
		return $c;
	}

	/** 40 phone calls per IP per 10 minutes: a real scan needs two or three. */
	private static function rate_ok(): bool {
		$key = 'icol_gate_rl_' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$n   = (int) get_transient( $key );
		if ( $n >= 40 ) {
			return false;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}
}
