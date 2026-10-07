<?php
namespace Insiders\Collections;

use Insiders\Collections\Auth\Capabilities;
use Insiders\Collections\Domain\Policy;
use Insiders\Collections\Engine\Runner;
use Insiders\Collections\Support\Ids;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function activate(): void {
		Schema::install();
		Policy::seed();
		foreach ( Settings::PATH_SECRETS as $s ) {
			if ( ! Settings::has_secret( $s ) ) {
				Settings::set_secret( $s, Ids::token( 24 ) );
			}
		}
		if ( ! get_option( 'icol_settings' ) ) {
			// Explicit first save: every capability starts OFF, kill switch ON.
			Settings::set( array( 'kill_switch' => 1, 'display_only' => 1 ) );
		}
		Front\PayPage::rewrite();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( 'icol_tick' ) ) {
			wp_schedule_event( time() + 60, 'icol_minute', 'icol_tick' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'icol_tick' );
		flush_rewrite_rules();
		// Data is kept: financial records are never deleted by deactivation.
	}

	public static function boot(): void {
		if ( (int) get_option( 'icol_db_version', 0 ) < ICOL_DB_VERSION ) {
			Schema::install();
			Policy::seed();
		}
		Capabilities::register();
		add_filter(
			'cron_schedules',
			static function ( $s ) {
				$s['icol_minute'] = array( 'interval' => 60, 'display' => 'INSIDERS collections — every minute' );
				return $s;
			}
		);
		add_action( 'icol_tick', static fn() => Runner::tick( 'wp-cron' ) );
		add_action( 'rest_api_init', array( Rest\Api::class, 'register' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'plain_ok' ), 10, 4 );
		add_action( 'init', array( Front\PayPage::class, 'rewrite' ) );
		add_filter( 'query_vars', static fn( $v ) => array_merge( $v, array( 'icol_pay', 'icol_pay_return' ) ) );
		add_action( 'template_redirect', array( Front\PayPage::class, 'maybe_render' ), 0 );
		if ( is_admin() ) {
			Admin\Admin::register();
			add_action( 'admin_init', array( Admin\Diagnostics::class, 'maybe_run' ) );
			add_action( 'admin_notices', array( self::class, 'version_guard' ) );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'icol', Cli\Command::class );
		}
	}

	/** Tranzila's notify convention: a plain "OK" body with 200. */
	public static function plain_ok( $served, $result, $request, $server ) {
		if ( $request instanceof \WP_REST_Request && str_starts_with( $request->get_route(), '/icol/v1/webhooks/tranzila/' ) && $result instanceof \WP_REST_Response && 200 === $result->get_status() ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'OK';
			return true;
		}
		return $served;
	}

	/** Header version and ICOL_VERSION must agree; drift once shipped a stale cache-buster. */
	public static function version_guard(): void {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_plugin_data' ) ) {
			return;
		}
		$data = get_plugin_data( ICOL_FILE, false, false );
		if ( ( $data['Version'] ?? '' ) !== ICOL_VERSION ) {
			echo '<div class="notice notice-error"><p>INSIDERS Collections: גרסת הכותרת (' . esc_html( $data['Version'] ?? '' ) . ') שונה מ-ICOL_VERSION (' . esc_html( ICOL_VERSION ) . ').</p></div>';
		}
		if ( ! \Insiders\Collections\Support\Crypto::available() ) {
			echo '<div class="notice notice-warning"><p>INSIDERS Collections: חסר ICOL_ENCRYPTION_KEY ב-wp-config.php. בלי מפתח לא נשמרים טוקנים ומפתחות API, ותוכן אירועים נשמר מושחר בלבד.</p></div>';
		}
	}
}
