<?php
namespace Insiders\Collections\Front;

use Insiders\Collections\Security\WebAuthn;

defined( 'ABSPATH' ) || exit;

/**
 * /?icol_gate=<challenge id> — the page the phone opens after scanning. A query
 * argument rather than a rewrite rule: nothing to flush, and the id is the only input.
 * The page holds no data; it asks the REST API for what to show, and the passkey
 * ceremony runs in the phone's own browser (Safari / Chrome) against this origin.
 */
final class GatePage {
	public static string $nonce = '';

	public static function maybe_render(): void {
		$id = (string) get_query_var( 'icol_gate' );
		if ( '' === $id ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, max-age=0', true );
		self::$nonce = base64_encode( random_bytes( 12 ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( "Content-Security-Policy: default-src 'none'; script-src 'nonce-" . self::$nonce . "'; connect-src 'self'; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'", true );
		header( 'Permissions-Policy: publickey-credentials-get=(self), publickey-credentials-create=(self)', true );
		$valid  = (bool) preg_match( '/^[a-f0-9]{32}$/', $id );
		$config = array(
			'root'   => esc_url_raw( rest_url( 'icol/v1' ) ),
			'id'     => $valid ? $id : '',
			'secure' => WebAuthn::secure_context(),
			'logo'   => ICOL_URL . 'assets/brand/logo-white.svg',
		);
		status_header( $valid ? 200 : 404 );
		include ICOL_DIR . 'templates/gate-phone.php';
		exit;
	}
}
