<?php
namespace Insiders\Collections\Front;

use Insiders\Collections\Domain\PaymentRequests;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * /pay/<token> — the only screen a customer sees (designer brief: docs/design/).
 * GET shows state and never creates anything (link scanners and WhatsApp's
 * preview bot open every link). The provider request is created on POST,
 * after a fresh balance read. No name, phone or amount is a URL parameter.
 */
final class PayPage {
	public static string $nonce = '';

	public static function rewrite(): void {
		$base = trim( (string) Settings::get( 'pay_base_path' ), '/' );
		add_rewrite_rule( '^' . preg_quote( $base, '#' ) . '/([A-Za-z0-9_\-]{30,64})/return/?$', 'index.php?icol_pay=$matches[1]&icol_pay_return=1', 'top' );
		add_rewrite_rule( '^' . preg_quote( $base, '#' ) . '/([A-Za-z0-9_\-]{30,64})/?$', 'index.php?icol_pay=$matches[1]', 'top' );
	}

	private static function form_token( string $token ): string {
		return hash_hmac( 'sha256', 'pay|' . $token, wp_salt( 'nonce' ) );
	}

	public static function maybe_render(): void {
		$token = (string) get_query_var( 'icol_pay' );
		if ( '' === $token ) {
			return;
		}
		nocache_headers();
		self::$nonce = base64_encode( random_bytes( 12 ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( "Content-Security-Policy: default-src 'self'; script-src 'nonce-" . self::$nonce . "'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data: https:; form-action 'self' https://*.tranzila.com; frame-ancestors 'none'", true );

		if ( get_query_var( 'icol_pay_return' ) ) {
			// Returning from Tranzila is not proof of payment (§8): the page promises a WhatsApp confirmation, nothing more.
			$state = PaymentRequests::page_state( $token );
			self::page( array( 'state' => 'paid' === $state['state'] ? 'paid' : ( 'not_found' === $state['state'] ? 'not_found' : 'returned' ) ), $token );
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			$given = (string) ( $_POST['t'] ?? '' );
			if ( ! hash_equals( self::form_token( $token ), $given ) ) {
				self::page( array( 'state' => 'error' ), $token );
			}
			$res = PaymentRequests::checkout( $token );
			if ( 'redirect' === $res['result'] && ! empty( $res['url'] ) && preg_match( '#^https://([a-z0-9-]+\.)*tranzila\.com/#i', $res['url'] ) ) {
				wp_redirect( $res['url'], 303 );
				exit;
			}
			if ( 'changed' === $res['result'] && ! empty( $res['url'] ) ) {
				wp_safe_redirect( $res['url'], 303 );
				exit;
			}
			self::page( array( 'state' => 'redirect' === $res['result'] ? 'error' : $res['result'] ), $token );
		}
		self::page( PaymentRequests::page_state( $token ), $token );
	}

	private static function page( array $s, string $token ): void {
		status_header( 'not_found' === $s['state'] ? 404 : 200 );
		$state      = $s['state'];
		$form_token = self::form_token( $token );
		$action     = PaymentRequests::url( $token );
		include ICOL_DIR . 'templates/pay-page.php';
		exit;
	}
}
