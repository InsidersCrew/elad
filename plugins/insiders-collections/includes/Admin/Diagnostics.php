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
				echo "\nהשלב הבא: " . ( \Insiders\Collections\Integrations\RevenueDashboard\FinanceDashboard::available() ? 'החיבור לדשבורד ההכנסות פעיל. לבדוק ש-stale הוא false ושיש מחיר בשדה penalty_gross.' : 'תוסף דשבורד ההכנסות לא נמצא. לוודא שהוא פעיל, או לחבר את מסנן icol_beginner_program_candidates (docs/revenue-dashboard-contract.md).' ) . "\n";
				break;
			case 'gate':
				self::dump( Gate::report() );
				echo "\nהשלב הבא: " . ( Gate::owner_id() ? 'לפתוח את מסך התשלומים ולחבר את הטלפון.' : 'לפתוח את מסך התשלומים, ללחוץ ״להגדיר אותי כבעלים של המערכת״ ולחבר את הטלפון.' ) . "\n";
				break;
			case 'revenue_candidates':
				self::dump( Revenue::candidates() );
				break;
			case 'program':
				self::dump( self::program() );
				break;
			case 'tranzila_auth':
				self::tranzila_auth();
				break;
			case 'tranzila_txn':
				self::tranzila_txn();
				break;
			case 'card_charge_stats':
				self::dump( self::card_charge_stats() );
				break;
			case 'cashflow':
				if ( ! empty( $_GET['sync'] ) ) {
					try {
						self::dump( array( 'sync' => \Insiders\Collections\Domain\Cashflow::sync() ) );
					} catch ( \Throwable $e ) {
						echo 'sync failed: ' . $e->getMessage() . "\n";
					}
				}
				// Exactly what the finance dashboard receives from the filter.
				self::dump( apply_filters( 'ifd_cashflow_forecast', null, array( 'months' => 6 ) ) ?? 'אין עדיין נתונים: הסנכרון לא הצליח אף פעם. להריץ עם &sync=1' );
				break;
			case 'tranzila_samples':
				self::tranzila_samples();
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
				if ( 'file' === \Insiders\Collections\Support\Crypto::source() || 'wp-config' === \Insiders\Collections\Support\Crypto::source() ) {
					echo 'מפתח ההצפנה כבר קיים (' . ( 'file' === \Insiders\Collections\Support\Crypto::source() ? 'נוצר אוטומטית בקובץ wp-content/' . \Insiders\Collections\Support\Crypto::KEY_FILE : 'מוגדר ב-wp-config.php' ) . "). אין צורך לעשות דבר.\n";
					echo "אין להחליף אותו: מפתח חדש יהפוך את מה שנשמר עד עכשיו ללא קריא.\n";
					break;
				}
				echo "האתר לא אפשר ליצור את קובץ המפתח אוטומטית. הוסיפו את השורה הבאה ל-wp-config.php, מעל השורה \"That's all, stop editing\":\n\n";
				echo "define( 'ICOL_ENCRYPTION_KEY', 'base64:" . base64_encode( random_bytes( 32 ) ) . "' );\n\n";
				echo "שמרו עותק של המפתח במקום בטוח (מנהל סיסמאות). בלי המפתח לא ניתן לקרוא טוקנים ומפתחות שנשמרו.\n";
				echo "המפתח לא נשמר באתר. כל רענון של הכלי יוצר מפתח חדש, יש להשתמש רק באחד.\n";

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
		foreach ( array( 'genkey' => 'יצירת מפתח הצפנה ל-wp-config.php (פעם אחת, לפני חיבור ספקים)', 'syntax' => 'בדיקת תחביר לכל קבצי התוסף (token_get_all)', 'schema' => 'טבלאות ומנוע InnoDB', 'health' => 'חותמות זמן, תורים וחיבורים', 'gate' => 'שער הסריקה: בעל המערכת, טלפונים וחיבורים', 'revenue_probe' => 'מה תוסף דשבורד ההכנסות חושף באתר', 'revenue_candidates' => 'מועמדים לפי המיפוי הנוכחי', 'program' => 'תוכנית למתחילים: הגדרות, מחירון, תווית, ומי ייכנס לליווי (בלי לכתוב דבר)', 'tranzila_auth' => 'בדיקת חתימת HMAC מול קריאה בטוחה (שתי האפשרויות)', 'tranzila_txn' => 'עסקה מדוח העסקאות: האם יש טוקן ותוקף (עם &index= או &from=&to=, קריאה בלבד)', 'tranzila_samples' => 'חמש ההודעות האחרונות שהתקבלו מטרנזילה, בלי פרטי כרטיס', 'card_charge_stats' => 'חיובים מהכרטיס השמור: הצלחות וסירובים, Apple Pay מול כרטיס רגיל', 'cashflow' => 'תזרים צפוי כפי שדשבורד ההכנסות מקבל אותו (עם &sync=1 מושך עכשיו מטרנזילה)', 'wati_ping' => 'קריאה בטוחה ל-WATI', 'tick' => 'הרצת מחזור עבודה אחד עכשיו' ) as $t => $label ) {
			echo str_pad( $t, 20 ) . $label . "\n  " . self::link( $t ) . "\n";
		}
	}

	/** The real parser, for a host without php -l. Brace counting misses a broken heredoc; this doesn't. */
	private static function syntax(): void {
		// Templates too: a broken template only fails when a customer opens the pay page.
		$it  = new \AppendIterator();
		foreach ( array( 'includes', 'templates' ) as $dir ) {
			$it->append( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( ICOL_DIR . $dir, \FilesystemIterator::SKIP_DOTS ) ) );
		}
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

	/** Beginner program, read-only: what the hourly job would do now, without doing it. */
	private static function program(): array {
		$fd    = \Insiders\Collections\Integrations\RevenueDashboard\FinanceDashboard::class;
		$S     = \Insiders\Collections\Support\Settings::class;
		$Db    = \Insiders\Collections\Support\Db::class;
		$today = \Insiders\Collections\Support\Clock::today();
		$until = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) + (int) $S::get( 'journey_start_days' ) * DAY_IN_SECONDS );
		$rows  = $fd::available() ? $fd::unresolved_until( $until ) : array();
		$taken = array_column( $Db::rows( 'SELECT candidate_key, status FROM ' . $Db::t( 'program_candidates' ) ), 'status', 'candidate_key' );
		$would = array();
		foreach ( $rows as $r ) {
			$n   = Revenue::normalize( $r );
			$key = $n ? Revenue::candidate_key( $n ) : '';
			$would[] = array( 'person' => $r['pipedrive_person_id'], 'deal' => $r['pipedrive_deal_id'], 'deadline' => $r['deadline'], 'track' => $r['deadline'] < $today ? 'late' : 'reach', 'status' => $taken[ $key ] ?? 'ייכנס בריצה הבאה' );
		}
		$label = (string) $S::get( 'no_registration_label' );
		return array(
			'enabled'          => $S::on( 'journey_enabled' ),
			'contact_basis'    => '' !== trim( (string) $S::get( 'journey_contact_basis' ) ) ? 'מתועד' : 'חסר (חובה לפני הפעלה)',
			'display_only'     => $S::on( 'display_only' ),
			'start_days'       => (int) $S::get( 'journey_start_days' ),
			'price_table'      => array_map( static fn( $p ) => $p['from'] . ': ' . ( $p['total'] / 100 ) . ' ₪, דמי רישום ' . ( $p['registration'] / 100 ) . ' ₪', \Insiders\Collections\Domain\Pricing::table() ),
			'price_table_errors' => \Insiders\Collections\Domain\Pricing::validate( (string) $S::get( 'program_price_table' ) ),
			'no_fee_label'     => $label . ' → ' . ( \Insiders\Collections\Integrations\Pipedrive\Client::configured() ? ( \Insiders\Collections\Integrations\Pipedrive\Client::label_id( $label ) ?? 'לא נמצאה תווית בשם הזה בפייפדרייב' ) : 'פייפדרייב לא מחובר' ),
			'declined_reasons' => \Insiders\Collections\Domain\Journey::declined_reasons(),
			'finance_dashboard' => $fd::available() ? array( 'stale' => $fd::contract()['stale'], 'sync_ok_at' => $fd::contract()['sync_ok_at'], 'ledger_for_credit' => $fd::ledger_available() ) : 'לא נמצא',
			'cases'            => $Db::rows( 'SELECT phase, COALESCE(track, \'-\') AS track, workflow_state, COUNT(*) AS n FROM ' . $Db::t( 'cases' ) . " WHERE source_type = 'non_open_charge' GROUP BY phase, track, workflow_state" ),
			'last_run'         => Runner::beats()['journey'] ?? 'עוד לא רץ',
			// One real deal read back, to see that Pipedrive returns status, lost reason and labels as expected.
			'deal_sample'      => ( static function () use ( $Db ) {
				$deal = (int) $Db::value( 'SELECT a.pipedrive_deal_id FROM ' . $Db::t( 'agreements' ) . " a WHERE a.type = 'beginner_program' AND a.pipedrive_deal_id IS NOT NULL ORDER BY a.id DESC LIMIT 1" );
				if ( ! $deal || ! \Insiders\Collections\Integrations\Pipedrive\Client::configured() ) {
					return 'אין עדיין דיל לבדיקה';
				}
				$r = \Insiders\Collections\Integrations\Pipedrive\Client::deals( array( $deal ) );
				return $r['deals'][ $deal ] ?? ( 'הדיל ' . $deal . ' לא חזר מפייפדרייב' . ( empty( $r['error'] ) ? '' : ': ' . $r['error'] ) );
			} )(),
			'unresolved_within_window' => count( $would ),
			'students'         => array_slice( $would, 0, 60 ),
		);
	}

	private static function tranzila_auth(): void {
		if ( ! Tranzila::configured() ) {
			echo "Tranzila not configured (app key + secret).\n";
			return;
		}
		foreach ( array( 'secret_time_nonce', 'time_nonce_secret' ) as $order ) {
			$headers = Tranzila::auth_headers( null, null, $order );
			$r       = Http::request( 'POST', rtrim( (string) \Insiders\Collections\Support\Settings::get( 'tranzila_api_base' ), '/' ) . '/v1/stos/get', array( 'headers' => $headers, 'body' => wp_json_encode( array( 'terminal_name' => (string) \Insiders\Collections\Support\Settings::get( 'tranzila_my_billing_terminal' ), 'sto_status' => 'active' ) ) ) );
			if ( 200 === (int) $r['status'] ) {
				$j    = json_decode( (string) $r['body'], true );
				$stos = is_array( $j['stos'] ?? null ) ? $j['stos'] : array();
				echo $order . ': HTTP 200, החתימה עובדת. הוראות קבע פעילות שהוחזרו: ' . count( $stos ) . "\n";
				if ( $stos ) {
					echo "  שדות של הוראה ראשונה (ערכים מוסתרים):\n";
					self::print_fields( (array) $stos[0], '    ' );
				}
			} else {
				$auth = in_array( (int) $r['status'], array( 401, 403 ), true ) ? ' (החתימה נדחתה)' : '';
				echo $order . ': HTTP ' . $r['status'] . $auth . ' ' . mb_substr( (string) $r['body'], 0, 200 ) . "\n";
			}
		}
		echo "\nהסדר שהחזיר 200 הוא הנכון: הגדרות ובריאות ← חיבורים ← סדר HMAC.\n";
	}

	/**
	 * Read-only look at the transaction report: does a transaction carry a reusable token,
	 * and which fields does the report return. Values that identify a person or a card are hidden.
	 */
	private static function tranzila_txn(): void {
		if ( ! Tranzila::configured() ) {
			echo "טרנזילה לא מחוברת: חסרים app key ו-secret בהגדרות ← חיבורים.\n";
			return;
		}
		$terminal = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) ( $_GET['terminal'] ?? 'insiders' ) ) );
		$index    = preg_replace( '/\D/', '', (string) ( $_GET['index'] ?? '' ) );
		$from     = (string) ( $_GET['from'] ?? '' );
		$to       = (string) ( $_GET['to'] ?? '' );
		$date     = static fn( string $d ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
		if ( '' === $index && ! $date( $from ) ) {
			echo "שימוש: להוסיף לכתובת אחד מאלה, ולרענן.\n";
			echo "  &index=12345            עסקה אחת לפי מספר העסקה בטרנזילה\n";
			echo "  &from=2026-09-01&to=2026-09-07   עד 20 עסקאות בטווח תאריכים\n";
			echo "  &terminal=insiderstok   מסוף אחר (ברירת המחדל: insiders)\n";
			return;
		}
		$filter = '' !== $index
			? array( 'transaction_index' => (int) $index )
			: array( 'transaction_start_date' => $from, 'transaction_end_date' => $date( $to ) ? $to : $from );
		$r = Tranzila::transactions( $terminal, $filter, 1, 20 );
		if ( ! $r['ok'] ) {
			echo 'הקריאה לדוח העסקאות נכשלה: ' . $r['error'] . "\n";
			echo "אם tranzila_auth עובד והכלי הזה לא, צורת הבקשה לדוח שונה ממה שמימשנו. לשלוח את הפלט לפיתוח.\n";
			return;
		}
		echo 'מסוף: ' . $terminal . ' · עסקאות שהוחזרו: ' . count( $r['rows'] ) . ( $r['total'] ? ' מתוך ' . $r['total'] : '' ) . "\n";
		$fields = Tranzila::terminal_fields( $terminal );
		$labels = array();
		if ( $fields ) {
			echo "\nשדות שהמסוף מגדיר לעסקה (שם בדוח, כותרת):\n";
			foreach ( $fields as $id => $f ) {
				$labels[ 'user_defined_' . $id ] = trim( $f['name'] . ', ' . $f['caption'], ', ' );
				echo '  user_defined_' . $id . ': ' . $labels[ 'user_defined_' . $id ] . "\n";
			}
		}
		foreach ( $r['rows'] as $i => $row ) {
			$n   = Tranzila::normalize_report_row( (array) $row );
			$exp = (string) $n['expiry'];
			$valid = '';
			if ( preg_match( '/^(\d{2})(\d{2})$/', $exp, $m ) ) {
				$last  = gmmktime( 0, 0, 0, (int) $m[1] + 1, 1, 2000 + (int) $m[2] ) - 1;
				$valid = $last >= time() ? 'בתוקף' : 'פג תוקף';
			}
			echo "\n--- עסקה " . ( $i + 1 ) . "\n";
			echo 'מספר עסקה: ' . $n['index'] . ' · תאריך: ' . $n['date'] . ' · סכום: ' . ( null === $n['amount_minor'] ? '?' : \Insiders\Collections\Support\Money::ils( (int) $n['amount_minor'] ) ) . ' · קוד תשובה: ' . $n['response'] . ' · tranmode: ' . $n['tranmode'] . ( '' !== $n['sto_id'] ? ' · הוראת קבע: ' . $n['sto_id'] : '' ) . "\n";
			echo 'טוקן לשימוש חוזר: ' . ( '' !== $n['token'] ? 'יש (' . strlen( $n['token'] ) . ' תווים, מסתיים ב-' . substr( $n['token'], -4 ) . ')' : 'אין' ) . "\n";
			echo 'תוקף הכרטיס: ' . ( '' !== $valid ? $valid : 'לא הוחזר' ) . "\n";
			echo "שדות שהדוח מחזיר (ערכים מזהים מוסתרים):\n";
			self::print_fields( (array) $row, '  ', 0, $labels );
		}
	}

	/** How saved-card charges fared, Apple Pay against ordinary cards: the real answer to "does an old Apple Pay token still charge". */
	private static function card_charge_stats(): array {
		$rows = \Insiders\Collections\Support\Db::rows( 'SELECT after_json FROM ' . \Insiders\Collections\Support\Db::t( 'audit_log' ) . " WHERE action = 'card_charge.result' ORDER BY id" );
		$out  = array( 'apple_pay' => array( 'approved' => 0, 'declined' => 0, 'unknown' => 0, 'codes' => array() ), 'card' => array( 'approved' => 0, 'declined' => 0, 'unknown' => 0, 'codes' => array() ) );
		foreach ( $rows as $r ) {
			$a   = (array) json_decode( (string) $r['after_json'], true );
			$k   = 'APPLE_PAY' === ( $a['broker'] ?? '' ) ? 'apple_pay' : 'card';
			$o   = in_array( $a['outcome'] ?? '', array( 'approved', 'declined', 'unknown' ), true ) ? $a['outcome'] : 'unknown';
			++$out[ $k ][ $o ];
			if ( 'declined' === $o && '' !== (string) ( $a['response'] ?? '' ) ) {
				$out[ $k ]['codes'][ (string) $a['response'] ] = ( $out[ $k ]['codes'][ (string) $a['response'] ] ?? 0 ) + 1;
			}
		}
		$out['skipped_apple_pay_by_age'] = (int) \Insiders\Collections\Support\Db::value( 'SELECT COUNT(*) FROM ' . \Insiders\Collections\Support\Db::t( 'audit_log' ) . " WHERE action = 'card_charge.skip' AND reason LIKE %s", '%Apple Pay%' );
		return $out;
	}

	/** The last notifications Tranzila sent, as stored (card material already removed), for mapping the cycle and pr_id fields. */
	private static function tranzila_samples(): void {
		$rows = \Insiders\Collections\Support\Db::rows( 'SELECT id, received_at, processing_state, last_error, payload_redacted FROM ' . \Insiders\Collections\Support\Db::t( 'inbox_events' ) . " WHERE provider = 'tranzila' ORDER BY id DESC LIMIT 5" );
		if ( ! $rows ) {
			echo "עדיין לא התקבלה הודעה מטרנזילה. אחרי שמגדירים את כתובת ה-Notify ב-My Billing, ההודעה הראשונה תופיע כאן.\n";
			return;
		}
		foreach ( $rows as $row ) {
			echo '--- הודעה #' . $row['id'] . ' · התקבלה ' . $row['received_at'] . ' · מצב: ' . $row['processing_state'] . ( $row['last_error'] ? ' · שגיאה: ' . $row['last_error'] : '' ) . "\n";
			$payload = json_decode( (string) $row['payload_redacted'], true );
			if ( is_array( $payload ) ) {
				self::print_fields( $payload, '  ' );
			} else {
				echo "  (לא ניתן לקרוא את התוכן)\n";
			}
			echo "\n";
		}
	}

	/** Field names with their values, hiding anything that identifies a person or a card. */
	private static function print_fields( array $data, string $pad, int $depth = 0, array $labels = array() ): void {
		// Card type is not identifying and tells which network the token belongs to.
		$plain = array( 'card_brand', 'card_issuer', 'card_description', 'card_type', 'card_type_name' );
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				echo $pad . $k . ":\n";
				if ( $depth < 2 ) {
					self::print_fields( $v, $pad . '  ', $depth + 1 );
				}
				continue;
			}
			$val = (string) ( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : $v );
			if ( '' === $val ) {
				$show = '(ריק)';
			} elseif ( in_array( (string) $k, $plain, true ) ) {
				$show = mb_substr( $val, 0, 60 );
			} elseif ( preg_match( '/(^|_)(tk|cc|cvv)|cavv|xid|tranzilatk|token|card|expir|exp(date|month|year|mon)|name|mail|phone|mobile|contact|myid|id_?num|holder|address|ssn|^tz$|remarks|comment/i', (string) $k ) || preg_match( '/^user_defined_/i', (string) $k ) || preg_match( '/\d{9,}|@/', $val ) || '[redacted]' === $val ) {
				$show = '[מוסתר, ' . mb_strlen( $val ) . ' תווים]';
			} else {
				$show = mb_substr( $val, 0, 60 );
			}
			echo $pad . $k . ( isset( $labels[ (string) $k ] ) ? ' (' . $labels[ (string) $k ] . ')' : '' ) . ': ' . $show . "\n";
		}
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
