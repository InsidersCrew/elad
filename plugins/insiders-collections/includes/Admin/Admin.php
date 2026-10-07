<?php
namespace Insiders\Collections\Admin;

use Insiders\Collections\Auth\Capabilities;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/** One admin page hosting the app. No build step: plain JS + CSS scoped under .icol-app. */
final class Admin {

	public static function register(): void {
		add_action(
			'admin_menu',
			static function () {
				add_menu_page( 'תשלומים וגבייה', 'תשלומים וגבייה', 'icol_view', 'icol', array( self::class, 'render' ), 'dashicons-money-alt', 3 );
			}
		);
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_icol' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'icol-fonts', 'https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;600;700;800&display=swap', array(), null );
		wp_enqueue_style( 'icol-admin', ICOL_URL . 'assets/admin.css', array(), ICOL_VERSION );
		wp_enqueue_script( 'icol-admin', ICOL_URL . 'assets/admin.js', array(), ICOL_VERSION, true );
		$caps = array();
		foreach ( Capabilities::all_caps() as $c ) {
			$caps[ $c ] = current_user_can( $c );
		}
		wp_localize_script(
			'icol-admin',
			'ICOL',
			array(
				'root'    => esc_url_raw( rest_url( 'icol/v1' ) ),
				'users_url' => esc_url_raw( rest_url( 'wp/v2/users' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'user'    => array( 'id' => get_current_user_id(), 'name' => wp_get_current_user()->display_name, 'role' => Capabilities::role_of( get_current_user_id() ) ),
				'caps'    => $caps,
				'version' => ICOL_VERSION,
				'diag'    => current_user_can( 'manage_options' ) ? array(
					'tools' => html_entity_decode( wp_nonce_url( admin_url( 'admin.php?icol_diag=tools' ), 'icol_diag' ) ),
				) : null,
			)
		);
	}

	public static function render(): void {
		echo '<div id="icol-root" class="icol-app" dir="rtl"><div class="icol-boot">טוען…</div></div>';
	}
}
