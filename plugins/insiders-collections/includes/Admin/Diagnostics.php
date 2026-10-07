<?php
namespace Insiders\Collections\Admin;

use Insiders\Collections\Engine\Runner;
use Insiders\Collections\Integrations\RevenueDashboard\Adapter as Revenue;
use Insiders\Collections\Integrations\Tranzila\Client as Tranzila;
use Insiders\Collections\Integrations\Wati\Client as Wati;
use Insiders\Collections\Rest\Views;
use Insiders\Collections\Schema;
use Insiders\Collections\Security\Gate;
use Insiders\Collections\Support\Http;

defined( 'ABSPATH' ) || exit;

/**
 * GET-only diagnostics (?icol_diag=...), manage_options + nonce. GET because
 * Cloudways ModSecurity blocks POST for tools like these. Output is plain text,
 * arrays printed readably, "never ran" distinguished from "ran and found nothing".
 */
final class Diagnostics {

	public static function maybe_run(): void {
		if ( empty( $_GET['icol_diag'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'icol_diag' ) ) {
			wp_die( 'forbidden', 403 );
		}
		$tool = sanitize_key( (string) $_GET['icol_diag'] );
		header( 'Content-Type: text/plain; charset=utf-8' );
		// Tools that show no customer data run before the first phone is paired (install order in README).
		if ( ! in_array( $tool, array( 'tools', 'genkey', 'syntax', 'schema', 'health', 'gate' ), true ) && ! Gate::unlocked() ) {
			echo "המערכת נעולה. יש לפתוח את מסך התשלומים בסריקה מהטלפון ואז להריץ את הכלי שוב.\n";
			exit;
		}
		switch ( $tool ) {
			case 'tools':
				self::tools();
				break;
			case 'syntax':
				self::syntax();
				break;
			case 'health':
				self::dump( Views::health() );
				break;
			case 'revenue_probe':
				self::dump( Revenue::probe() );
				echo "\nהשלב הבא: לבחור מפתחות למועד אחרון / חשבון נפתח / הצטרפות בהגדרות, או לחבר את מסנן icol_beginner_program_candidates (docs/revenue-dashboard-contract.md).\n";
				break;
			case 'gate':
				self::dump( Gate::report() );
				echo "\nהשלב הבא: " . ( Gate::owner_id() ? 'לפתוח את מסך התשלומים ולחבר את הטלפון.' : "להוסיף ל-wp-config.php את define( 'ICOL_GATE_OWNER', '" . wp_get_current_user()->user_login . "' );" ) . "\n";
				break;
			case 'revenue_candidates':
				self::dump( Revenue::candidates() );
				break;
			case 'tranzila_auth':
				self::tranzila_auth();
				break;
			case 'wati_ping':
				self::dump( Wati::configured() ? Wati::ping() : array( 'configured' => false ) );
				break;
			case 'tick':
				self::dump( Runner::tick( 'diagnostic' ) );
				break;
			case 'schema':
				self::dump( Schema::verify() );
				break;
			case 'genkey':
				// A fresh key every time; nothing is stored. Paste it into wp-config.php once and keep a backup:
				// losing it makes stored tokens and API keys unreadable.
				echo "הוסיפו את השורה הבאה ל-wp-config.php, מעל השורה \"That's all, stop editing\":\n\n";
				echo "define( 'ICOL_ENCRYPTION_KEY', 'base64:" . base64_encode( random_bytes( 32 ) ) . "' );\n\n";
				echo "שמרו עותק של המפתח במקום בטוח (מנהל סיסמאות). בלי המפתח לא ניתן לקרוא טוקנים ומפתחות שנשמרו.\n";
				echo "המפתח לא נשמר באתר. כל רענון של הכלי יוצר מפתח חדש, יש להשתמש רק באחד.\n";
				if ( ! defined( 'ICOL_GATE_OWNER' ) ) {
					echo "\nבאותו מקום מוסיפים גם את בעל המערכת. רק הטלפון שלו יאשר טלפונים אחרים:\n\n";
					echo "define( 'ICOL_GATE_OWNER', '" . wp_get_current_user()->user_login . "' );\n";
				}
				echo \Insiders\Collections\Support\Crypto::available() ? "\nשימו לב: כבר מוגדר מפתח באתר. אין להחליף אותו.\n" : '';
				break;
			default:
				echo "unknown tool\n";
		}
		exit;
	}

	private static function link( string $tool ): string {
		return html_entity_decode( wp_nonce_url( admin_url( 'admin.php?icol_diag=' . $tool ), 'icol_diag' ) );
	}

	private static function tools(): void {
		echo "INSIDERS Collections, כלי אבחון (" . ICOL_VERSION . ")\nסדר מומלץ אחרי העלאה: syntax → schema → health\n\n";
		foreach ( array( 'genkey' => 'יצירת מפתח הצפנה ל-wp-config.php (פעם אחת, לפני חיבור ספקים)', 'syntax' => 'בדיקת תחביר לכל קבצי התוסף (token_get_all)', 'schema' => 'טבלאות ומנוע InnoDB', 'health' => 'חותמות זמן, תורים וחיבורים', 'gate' => 'שער הסריקה: בעל המערכת, טלפונים וחיבורים', 'revenue_probe' => 'מה תוסף דשבורד ההכנסות חושף באתר', 'revenue_candidates' => 'מועמדים לפי המיפוי הנוכחי', 'tranzila_auth' => 'בדיקת חתימת HMAC מול קריאה בטוחה (שתי האפשרויות)', 'wati_ping' => 'קריאה בטוחה ל-WATI', 'tick' => 'הרצת מחזור עבודה אחד עכשיו' ) as $t => $label ) {
			echo str_pad( $t, 20 ) . $label . "\n  " . self::link( $t ) . "\n";
		}
	}

	/** The real parser, for a host without php -l. Brace counting misses a broken heredoc; this doesn't. */
	private static function syntax(): void {
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( ICOL_DIR . 'includes' ) );
		$bad = 0;
		$n   = 0;
		foreach ( $it as $f ) {
			if ( 'php' !== $f->getExtension() ) {
				continue;
			}
			++$n;
			try {
				token_get_all( file_get_contents( $f->getPathname() ), TOKEN_PARSE );
			} catch ( \ParseError $e ) {
				++$bad;
				echo 'FAIL ' . str_replace( ICOL_DIR, '', $f->getPathname() ) . ':' . $e->getLine() . ' ' . $e->getMessage() . "\n";
			}
		}
		echo "checked {$n} files, {$bad} failed\n";
	}

	private static function tranzila_auth(): void {
		if ( ! Tranzila::configured() ) {
			echo "Tranzila not configured (app key + secret).\n";
			return;
		}
		foreach ( array( 'secret_time_nonce', 'time_nonce_secret' ) as $order ) {
			$headers = Tranzila::auth_headers( null, null, $order );
			$r       = Http::request( 'POST', rtrim( (string) \Insiders\Collections\Support\Settings::get( 'tranzila_api_base' ), '/' ) . '/v1/stos/get', array( 'headers' => $headers, 'body' => wp_json_encode( array( 'terminal_name' => (string) \Insiders\Collections\Support\Settings::get( 'tranzila_my_billing_terminal' ), 'sto_status' => 'active' ) ) ) );
			echo $order . ': HTTP ' . $r['status'] . ' ' . $r['outcome'] . ' ' . mb_substr( $r['body'], 0, 200 ) . "\n";
		}
		echo "\nהסדר שהחזיר 200 הוא הנכון, לשמור אותו בהגדרה tranzila_hmac_order.\n";
	}

	private static function dump( $data, int $depth = 0 ): void {
		if ( ! is_array( $data ) ) {
			echo var_export( $data, true ) . "\n";
			return;
		}
		if ( ! $data ) {
			echo str_repeat( '  ', $depth ) . "(ריק, רץ ולא נמצא דבר)\n";
		}
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				echo str_repeat( '  ', $depth ) . $k . ":\n";
				self::dump( $v, $depth + 1 );
			} else {
				echo str_repeat( '  ', $depth ) . $k . ': ' . ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : ( null === $v ? 'null' : (string) $v ) ) . "\n";
			}
		}
	}
}
