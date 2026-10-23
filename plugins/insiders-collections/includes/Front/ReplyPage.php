<?php
namespace Insiders\Collections\Front;

use Insiders\Collections\Domain\Messaging;
use Insiders\Collections\Domain\Templates;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * /?icol_reply=<token>: a button in a journey email, for a student without WhatsApp. The token names the
 * email and the button and is signed, so nothing about the student is in the address. GET only asks to
 * confirm (mail scanners open every link); the press is recorded on POST, exactly as the same WhatsApp
 * button would be (Messaging::handle_inbound), and the answer WhatsApp would have sent is shown here.
 */
final class ReplyPage {
	public static string $nonce = '';

	public static function url( string $local_id, int $button ): string {
		return add_query_arg( 'icol_reply', $local_id . '.' . $button . '.' . self::sig( $local_id, $button ), home_url( '/' ) );
	}

	private static function sig( string $local_id, int $button ): string {
		return substr( hash_hmac( 'sha256', 'reply|' . $local_id . '|' . $button, wp_salt( 'auth' ) ), 0, 24 );
	}

	private static function form_token( string $token ): string {
		return hash_hmac( 'sha256', 'reply-form|' . $token, wp_salt( 'nonce' ) );
	}

	public static function maybe_render(): void {
		$token = (string) get_query_var( 'icol_reply' );
		if ( '' === $token ) {
			return;
		}
		nocache_headers();
		self::$nonce = base64_encode( random_bytes( 12 ) );
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
		header( "Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'", true );

		$r = self::resolve( $token );
		if ( 'ok' !== $r['state'] ) {
			self::page( $r['state'], '', $token );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			self::page( 'confirm', $r['title'], $token );
		}
		if ( ! hash_equals( self::form_token( $token ), (string) ( $_POST['t'] ?? '' ) ) ) {
			self::page( 'error', $r['title'], $token );
		}
		$res = self::press( $token );
		self::page( $res['state'], $r['title'], $token, $res['ack'] );
	}

	/** The email and the button a token names: state ok, not_found or expired. */
	public static function resolve( string $token ): array {
		if ( ! preg_match( '/^([0-9a-f-]{36})\.(\d{1,2})\.([0-9a-f]{24})$/', $token, $p ) || ! hash_equals( self::sig( $p[1], (int) $p[2] ), $p[3] ) ) {
			return array( 'state' => 'not_found' );
		}
		$m     = Db::row( 'SELECT * FROM ' . Db::t( 'messages' ) . " WHERE local_id = %s AND direction = 'out' AND channel = 'email'", $p[1] );
		$tpl   = $m ? Templates::get( (string) $m['template_key'] ) : null;
		$title = array_values( (array) ( $tpl['buttons'] ?? array() ) )[ (int) $p[2] ] ?? null;
		if ( ! $m || null === $title ) {
			return array( 'state' => 'not_found' );
		}
		if ( ( Clock::ts( $m['created_at'] ) ?? 0 ) < Clock::now() - 120 * DAY_IN_SECONDS ) {
			return array( 'state' => 'expired' );
		}
		return array( 'state' => 'ok', 'message' => $m, 'title' => (string) $title, 'button' => (int) $p[2] );
	}

	/** The confirmed press: recorded as the same WhatsApp button would be. state done or again. */
	public static function press( string $token ): array {
		$r = self::resolve( $token );
		if ( 'ok' !== $r['state'] ) {
			return array( 'state' => $r['state'], 'ack' => '' );
		}
		Messaging::$email_ack = '';
		$res = Messaging::handle_inbound(
			(int) $r['message']['customer_id'],
			array(
				'provider'    => 'email',
				'provider_id' => 'btn:' . $r['message']['local_id'] . ':' . $r['button'],
				'text'        => $r['title'],
				'type'        => 'button',
				'button'      => true,
				'occurred_at' => Clock::utc(),
			)
		);
		return array( 'state' => ! empty( $res['duplicate'] ) ? 'again' : 'done', 'ack' => Messaging::$email_ack );
	}

	private static function page( string $state, string $title, string $token, string $ack = '' ): void {
		status_header( 'not_found' === $state ? 404 : 200 );
		$screens = array(
			'confirm'   => array( 'h' => 'רק לאשר', 'p' => 'לחצת על "' . $title . '" במייל שקיבלת מאיתנו. ללחוץ על הכפתור למטה כדי לשלוח לנו את התשובה.' ),
			'done'      => array( 'h' => 'קיבלנו, תודה', 'p' => '' !== $ack ? $ack : 'התשובה נשמרה. אם צריך משהו נוסף, נחזור אליך במייל.' ),
			'again'     => array( 'h' => 'כבר קיבלנו', 'p' => 'התשובה הזו כבר נשמרה אצלנו, אין צורך לשלוח שוב.' ),
			'expired'   => array( 'h' => 'הקישור כבר לא בתוקף', 'p' => 'אפשר להשיב למייל שקיבלת ונחזור אליך.' ),
			'not_found' => array( 'h' => 'הקישור לא נמצא', 'p' => 'ייתכן שהקישור לא הועתק במלואו. אפשר להשיב למייל שקיבלת ונחזור אליך.' ),
			'error'     => array( 'h' => 'משהו השתבש', 'p' => 'התשובה לא נשלחה. אפשר לחזור למייל וללחוץ שוב, או להשיב למייל.' ),
		);
		$sc   = $screens[ $state ] ?? $screens['error'];
		$form = 'confirm' === $state ? '<form method="post" action="' . esc_url( add_query_arg( 'icol_reply', $token, home_url( '/' ) ) ) . '"><input type="hidden" name="t" value="' . esc_attr( self::form_token( $token ) ) . '"><button type="submit">' . esc_html( $title ) . '</button></form>' : '';
		echo '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>INSIDERS</title>'
			. '<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;700&display=swap" rel="stylesheet">'
			. '<style>html{background:#05142F}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Heebo,Arial,sans-serif;color:#EAF0FA;padding:16px;box-sizing:border-box}'
			. '.card{max-width:440px;width:100%;background:#0B1F45;border:1px solid #1B3366;border-radius:20px;padding:28px 24px;box-sizing:border-box}'
			. '.brand{font-weight:700;letter-spacing:.08em;color:#8FA6CC;font-size:13px;margin-bottom:14px}h1{font-size:24px;margin:0 0 10px}p{margin:0 0 18px;line-height:1.7;white-space:pre-line;color:#C9D5EA}'
			. 'button{width:100%;border:0;border-radius:14px;background:#460FFF;color:#fff;font:700 17px Heebo,Arial,sans-serif;padding:15px 18px;cursor:pointer}button:focus-visible{outline:3px solid #9F87FF;outline-offset:3px}</style></head>'
			. '<body><main class="card"><div class="brand">INSIDERS</div><h1>' . esc_html( $sc['h'] ) . '</h1><p>' . esc_html( $sc['p'] ) . '</p>' . $form . '</main></body></html>';
		exit;
	}
}
