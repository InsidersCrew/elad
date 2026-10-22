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
			case 'roster':
				self::dump( self::roster() );
				break;
			case 'phone_check':
				self::dump( self::phone_check() );
				break;
			case 'registration_cards':
				self::dump( self::registration_cards() );
				break;
			case 'wati_contact':
				$e164 = \Insiders\Collections\Support\Phone::e164( (string) ( $_GET['phone'] ?? '' ) );
				if ( ! $e164 ) {
					echo "להוסיף לכתובת &phone= עם מספר טלפון, למשל &phone=0501234567\n";
					break;
				}
				$r = Wati::contact_name( $e164 );
				$first = trim( (string) ( preg_split( '/\s+/u', $r['name'] )[0] ?? '' ), " \t.,!?:;()\"" );
				$r['first_name_in_messages'] = $r['found'] && preg_match( '/^[\p{L}\p{M}\'\-]{2,30}$/u', $first ) ? $first : 'לא שמיש: נשאר השם מפייפדרייב, או "היי לך"';
				self::dump( $r );
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
		foreach ( array( 'genkey' => 'יצירת מפתח הצפנה ל-wp-config.php (פעם אחת, לפני חיבור ספקים)', 'syntax' => 'בדיקת תחביר לכל קבצי התוסף (token_get_all)', 'schema' => 'טבלאות ומנוע InnoDB', 'health' => 'חותמות זמן, תורים וחיבורים', 'gate' => 'שער הסריקה: בעל המערכת, טלפונים וחיבורים', 'revenue_probe' => 'מה תוסף דשבורד ההכנסות חושף באתר', 'revenue_candidates' => 'מועמדים לפי המיפוי הנוכחי', 'program' => 'תוכנית למתחילים: הגדרות, מחירון, תווית, ומי ייכנס לליווי (בלי לכתוב דבר)', 'wati_contact' => 'השם של איש קשר ב-WATI לפי טלפון, ואיך הוא ייכתב בהודעה (עם &phone=, קריאה בלבד)', 'phone_check' => 'למה מספר מסוים קיבל או לא קיבל הודעה: מצב, רשימה מדורגת, תיקים, הודעה מתוכננת, חסימות והודעות אחרונות (עם &phone=, קריאה בלבד)', 'registration_cards' => 'איך שילמו את דמי הרישום (Apple Pay או כרטיס) התלמידים שהמועד שלהם הכי קרוב, מדוח העסקאות של טרנזילה (עם &limit=, קריאה בלבד)', 'roster' => 'כל תלמידי התוכנית שדשבורד ההכנסות מכיר, ולמה כל אחד בליווי או לא (עם &from=YYYY-MM-DD, &persons=מזהים מפייפדרייב)', 'tranzila_auth' => 'בדיקת חתימת HMAC מול קריאה בטוחה (שתי האפשרויות)', 'tranzila_txn' => 'עסקה מדוח העסקאות: האם יש טוקן ותוקף (עם &index= או &from=&to=, קריאה בלבד)', 'tranzila_samples' => 'חמש ההודעות האחרונות שהתקבלו מטרנזילה, בלי פרטי כרטיס', 'card_charge_stats' => 'חיובים מהכרטיס השמור: הצלחות וסירובים, Apple Pay מול כרטיס רגיל', 'cashflow' => 'תזרים צפוי כפי שדשבורד ההכנסות מקבל אותו (עם &sync=1 מושך עכשיו מטרנזילה)', 'wati_ping' => 'קריאה בטוחה ל-WATI', 'tick' => 'הרצת מחזור עבודה אחד עכשיו' ) as $t => $label ) {
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
	/**
	 * Every program student the revenue dashboard knows, from a date, with the reason each one is or is not
	 * in the journey. Read only. &persons=1,2,3 also explains Pipedrive persons the dashboard does not have.
	 */
	private static function roster(): array {
		$fd = \Insiders\Collections\Integrations\RevenueDashboard\FinanceDashboard::class;
		$Db = \Insiders\Collections\Support\Db::class;
		$S  = \Insiders\Collections\Support\Settings::class;
		if ( ! $fd::available() ) {
			return array( 'error' => 'דשבורד ההכנסות לא זמין באתר' );
		}
		global $wpdb;
		$since = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_GET['from'] ?? '' ) ) ? (string) $_GET['from'] : '2026-06-01';
		$raw   = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.person_id, c.deal_id, c.signed_at, c.resolved_at, c.resolution_kind, c.signed_source, l.person_name FROM ' . $fd::table( 'commitments' ) . ' c LEFT JOIN ' . $fd::table( 'leads' ) . ' l ON l.person_id = c.person_id WHERE c.signed_at >= %s ORDER BY c.signed_at, c.deal_id',
				$since
			),
			ARRAY_A
		);
		$norm = array();
		foreach ( array_filter( array_map( array( Revenue::class, 'normalize' ), $fd::unresolved_until( '2099-12-31' ) ) ) as $r ) {
			$norm[ (int) $r['pipedrive_deal_id'] ] = $r;
		}
		$cands = array();
		foreach ( $Db::rows( 'SELECT candidate_key, status, snapshot, case_id FROM ' . $Db::t( 'program_candidates' ) ) as $pc ) {
			$cands[ $pc['candidate_key'] ] = $pc;
		}
		$start   = (int) $S::get( 'journey_start_days' );
		$from    = $fd::from_date();
		$today   = \Insiders\Collections\Support\Clock::today();
		$enabled = \Insiders\Collections\Domain\Journey::enabled();
		$rows    = array();
		$summary = array();
		$seen    = array();
		foreach ( $raw as $c ) {
			$deal   = (int) $c['deal_id'];
			$person = (int) $c['person_id'];
			$seen[ $person ] = true;
			$case   = $deal ? $Db::row( 'SELECT c.id, c.workflow_state, c.phase, c.track FROM ' . $Db::t( 'cases' ) . ' c JOIN ' . $Db::t( 'agreements' ) . ' a ON a.id = c.agreement_id WHERE a.pipedrive_deal_id = %d ORDER BY c.id DESC LIMIT 1', $deal ) : null;
			$n      = $norm[ $deal ] ?? null;
			$dl     = $n['effective_deadline'] ?? gmdate( 'Y-m-d', strtotime( substr( (string) $c['signed_at'], 0, 10 ) . ' UTC' ) + $fd::window_days() * DAY_IN_SECONDS );
			$pc     = $cands[ Revenue::candidate_key( array( 'pipedrive_person_id' => $person, 'pipedrive_deal_id' => $deal ) ) ] ?? null;
			$snap   = $pc ? (array) json_decode( (string) $pc['snapshot'], true ) : array();
			if ( $case ) {
				$bucket = 'בליווי או בטיפול';
				$why    = 'תיק #' . $case['id'] . ': ' . ( 'commitment' === $case['phase'] ? 'לפני המועד' : 'אחרי המועד' ) . ', ' . \Insiders\Collections\Domain\Workflow::label( $case['workflow_state'] ) . ( $case['track'] ? ', מסלול ' . $case['track'] : '' );
			} elseif ( $c['resolved_at'] ) {
				$bucket = 'סגור בדשבורד';
				$why    = ( 'fixed' === $c['resolution_kind'] ? 'שולם' : 'נפתח חשבון' ) . ' לפי דשבורד ההכנסות (' . substr( (string) $c['resolved_at'], 0, 10 ) . ')';
			} elseif ( in_array( (string) $c['signed_source'], array( 'resolution_first', 'reverted' ), true ) ) {
				$bucket = 'הדשבורד לא מחשיב כהתחייבות';
				$why    = 'בדשבורד הדיל מסומן ' . $c['signed_source'] . ' (הפתיחה נרשמה לפני ההצטרפות, או שההצטרפות בוטלה)';
			} elseif ( substr( (string) $c['signed_at'], 0, 10 ) < $from ) {
				$bucket = 'לפני תאריך ההתחלה';
				$why    = 'הצטרף לפני ' . $from . ' (תאריך ההתחלה בהגדרות התוכנית)';
			} elseif ( ! $n ) {
				$bucket = 'הדשבורד לא מחזיר כמועמד';
				$why    = 'בלי מזהה איש קשר תקין או בלי מועד';
			} elseif ( gmdate( 'Y-m-d', strtotime( $dl . ' UTC' ) - $start * DAY_IN_SECONDS ) > $today ) {
				$bucket = 'ממתין לתחילת הליווי';
				$why    = 'ייכנס אוטומטית ב-' . gmdate( 'Y-m-d', strtotime( $dl . ' UTC' ) - $start * DAY_IN_SECONDS ) . ' (' . $start . ' יום לפני המועד)';
			} elseif ( $pc && 'invalid' === $pc['status'] ) {
				$bucket = 'נתקע';
				$why    = 'מועד לא תקין';
			} elseif ( $pc && ! empty( $snap['enrich_error'] ) ) {
				$bucket = 'נתקע';
				$why    = 'לא הצלחנו למשוך פרטי קשר מפייפדרייב (' . (int) ( $snap['enrich_attempts'] ?? 0 ) . ' ניסיונות): ' . mb_substr( (string) $snap['enrich_error'], 0, 120 );
			} elseif ( $pc && ! in_array( $pc['status'], array( 'new', 'enrolling' ), true ) ) {
				$bucket = 'נתקע';
				$why    = 'סטטוס מועמד ' . $pc['status'] . ( $pc['case_id'] ? ', משויך לתיק #' . $pc['case_id'] : '' );
			} elseif ( $Db::value( 'SELECT id FROM ' . $Db::t( 'exceptions' ) . ' WHERE exception_key LIKE %s AND status = %s', 'journey_dup_person:' . $person . ':%', 'open' ) ) {
				$bucket = 'נתקע';
				$why    = 'לתלמיד יש עוד דיל הצטרפות עם תיק פתוח (חריג פתוח)';
			} else {
				$bucket = 'ייכנס בריצה הקרובה';
				$why    = $enabled ? 'אמור להיכנס בריצה השעתית הבאה' : 'הליווי כבוי בהגדרות, ולכן לא נכנס';
			}
			$summary[ $bucket ] = ( $summary[ $bucket ] ?? 0 ) + 1;
			$rows[] = array( 'name' => (string) $c['person_name'], 'person' => $person, 'deal' => $deal, 'joined' => substr( (string) $c['signed_at'], 0, 10 ), 'deadline' => $dl, 'status' => $bucket, 'why' => $why );
		}
		$extra = array();
		foreach ( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', (string) ( $_GET['persons'] ?? '' ) ) ) ) as $p ) {
			if ( isset( $seen[ $p ] ) ) {
				continue;
			}
			$any = $wpdb->get_row( $wpdb->prepare( 'SELECT signed_at, resolved_at, signed_source FROM ' . $fd::table( 'commitments' ) . ' WHERE person_id = %d ORDER BY signed_at DESC LIMIT 1', $p ), ARRAY_A );
			$extra[ $p ] = $any ? 'בדשבורד, אבל הצטרף לפני ' . $since . ' (' . substr( (string) $any['signed_at'], 0, 10 ) . '). להריץ שוב עם &from= מוקדם יותר' : 'לא נמצא בטבלת ההתחייבויות של דשבורד ההכנסות. הדשבורד לא מזהה אותו כמי שהצטרף לתוכנית (שלב הדיל בפייפדרייב, או שהסנכרון לא קרא אותו)';
		}
		return array(
			'from'         => $since,
			'start_date'   => $from,
			'start_days'   => $start,
			'journey_on'   => $enabled,
			'summary'      => $summary,
			'not_in_dashboard' => $extra ?: 'אפשר להוסיף &persons= עם מזהי אנשי קשר מפייפדרייב של תלמידים שחסרים, כדי לראות למה',
			'students'     => $rows,
		);
	}

	/**
	 * How the registration fee was paid (Apple Pay or a card) by the open program students whose deadline is
	 * nearest, from Tranzila's transaction report. Read only: one report query per student.
	 */
	/** Why a number got (or did not get) a message: every gate on the way, read only. */
	private static function phone_check(): array {
		$Db   = \Insiders\Collections\Support\Db::class;
		$S    = \Insiders\Collections\Support\Settings::class;
		$G    = \Insiders\Collections\Domain\SendGuard::class;
		$C    = \Insiders\Collections\Support\Clock::class;
		$e164 = \Insiders\Collections\Support\Phone::e164( (string) ( $_GET['phone'] ?? '' ) );
		if ( ! $e164 ) {
			return array( 'error' => 'להוסיף לכתובת &phone= עם מספר טלפון, למשל &phone=0501234567' );
		}
		$policy = \Insiders\Collections\Domain\Policy::effective_or_draft();
		$local  = static fn( $utc ) => $utc ? $C::local( (int) $C::ts( $utc ) )->format( 'd/m H:i' ) : '';
		$out    = array(
			'phone'   => $e164,
			'system'  => array(
				'display_only'       => $S::on( 'display_only' ),
				'customer_sends_on'  => $S::on( 'cap_customer_sends' ),
				'kill_switch'        => $S::on( 'kill_switch' ),
				'mode'               => $G::mode(),
				'staged_list'        => $G::allowlist() ?: 'ריקה (כולם)',
				'number_in_list'     => ! $G::allowlist() || in_array( $e164, $G::allowlist(), true ),
				'policy_approved'    => null !== \Insiders\Collections\Domain\Policy::current(),
				'now_in_send_window' => \Insiders\Collections\Domain\Calendar::in_window( $C::now(), $policy ),
				'next_window_opens'  => $C::local( \Insiders\Collections\Domain\Calendar::next_slot( $C::now(), $policy ) )->format( 'd/m H:i' ),
				'journey_on'         => \Insiders\Collections\Domain\Journey::enabled(),
			),
		);
		$customers = \Insiders\Collections\Domain\Customers::by_phone( $e164 );
		if ( ! $customers ) {
			$out['customers'] = 'אין לקוח עם המספר הזה בתוסף. הודעות יוצאות רק לתלמידים עם תיק, ולכן לא תצא הודעה למספר הזה';
			return $out;
		}
		foreach ( $customers as $cu ) {
			$cid  = (int) $cu['id'];
			$item = array(
				'customer'       => $cid,
				'name'           => $cu['full_name'],
				'first_name'     => $cu['first_name'] . ( (int) $cu['first_name_reliable'] ? '' : ' (לא בשימוש בהודעה)' ),
				'contact_status' => $cu['contact_status'],
				'whatsapp_permission' => var_export( \Insiders\Collections\Domain\Customers::permission( $cid, 'whatsapp' ), true ),
				'conversation_owner'  => $cu['conversation_owner'],
				'mode_for_this_number' => $G::mode( $cid ),
				'cases'          => array(),
			);
			foreach ( $Db::rows( 'SELECT id, phase, track, track_step, workflow_state, state_reason FROM ' . $Db::t( 'cases' ) . ' WHERE customer_id = %d ORDER BY id DESC LIMIT 5', $cid ) as $c ) {
				$id     = (int) $c['id'];
				$guard  = $G::check( $id, array( 'is_reminder' => true ) );
				$item['cases'][ $id ] = array(
					'state'    => \Insiders\Collections\Domain\Workflow::label( $c['workflow_state'] ) . ( $c['state_reason'] ? ': ' . $c['state_reason'] : '' ),
					'phase'    => $c['phase'] . ( $c['track'] ? ' / ' . $c['track'] : '' ) . ( $c['track_step'] ? ' / אחרון: ' . $c['track_step'] : '' ),
					'next_step' => 'commitment' === $c['phase'] ? ( \Insiders\Collections\Domain\Journey::next_step( \Insiders\Collections\Domain\Workflow::get( $id ) ) ?: 'אין שלב נוסף לפני המועד' ) : '',
					'planned'  => array_map( static fn( $a ) => $a['type'] . ' ' . ( json_decode( (string) $a['payload'], true )['step'] ?? '' ) . ' ב-' . $local( $a['run_at'] ) . ' (' . $a['state'] . ( $a['result'] ? ': ' . $a['result'] : '' ) . ')', $Db::rows( 'SELECT type, run_at, state, result, payload FROM ' . $Db::t( 'scheduled_actions' ) . ' WHERE case_id = %d ORDER BY id DESC LIMIT 5', $id ) ),
					'blockers_now' => $guard['blockers'] ? array_map( static fn( $b ) => $b['code'] . ' (' . $b['scope'] . '): ' . $b['message'], $guard['blockers'] ) : 'אין',
				);
			}
			$item['last_messages'] = array_map( static fn( $m ) => $local( $m['occurred_at'] ) . ' ' . $m['direction'] . ' ' . ( $m['template_key'] ?: $m['kind'] ) . ': ' . $m['delivery_state'], $Db::rows( 'SELECT occurred_at, direction, template_key, kind, delivery_state FROM ' . $Db::t( 'messages' ) . ' WHERE customer_id = %d ORDER BY id DESC LIMIT 8', $cid ) );
			$out['customers'][] = $item;
		}
		return $out;
	}

	private static function registration_cards(): array {
		$Db    = \Insiders\Collections\Support\Db::class;
		$limit = max( 1, min( 60, (int) ( $_GET['limit'] ?? 20 ) ) );
		$rows  = $Db::rows(
			'SELECT c.id, c.phase, c.workflow_state, u.full_name, u.phone_e164, a.signed_at, a.joined_at, a.account_open_deadline FROM ' . $Db::t( 'cases' ) . ' c JOIN ' . $Db::t( 'customers' ) . ' u ON u.id = c.customer_id JOIN ' . $Db::t( 'agreements' ) . " a ON a.id = c.agreement_id
			 WHERE c.source_type = 'non_open_charge' AND c.workflow_state <> 'closed' AND a.no_registration_fee = 0 ORDER BY a.account_open_deadline, c.id LIMIT %d",
			$limit
		);
		$terminal = (string) \Insiders\Collections\Support\Settings::get( 'tranzila_registration_terminal', 'insiders' );
		$apple    = array();
		$card     = array();
		$none     = array();
		foreach ( $rows as $r ) {
			$signed = $r['signed_at'] ?: $r['joined_at'];
			$line   = array( 'case' => (int) $r['id'], 'name' => (string) $r['full_name'], 'deadline' => (string) $r['account_open_deadline'], 'state' => \Insiders\Collections\Domain\Workflow::label( $r['workflow_state'] ) );
			if ( ! $signed || empty( $r['phone_e164'] ) ) {
				$none[] = $line + array( 'why' => 'אין תאריך הסכם או טלפון' );
				continue;
			}
			$from = gmdate( 'Y-m-d', strtotime( $signed . ' UTC' ) - 10 * DAY_IN_SECONDS );
			$to   = min( \Insiders\Collections\Support\Clock::today(), gmdate( 'Y-m-d', strtotime( $signed . ' UTC' ) + 40 * DAY_IN_SECONDS ) );
			$res  = Tranzila::card_payments_by_phone( $terminal, (string) $r['phone_e164'], $from, $to );
			if ( ! $res['ok'] ) {
				$none[] = $line + array( 'why' => 'דוח העסקאות לא זמין: ' . $res['error'] );
				continue;
			}
			if ( ! $res['rows'] ) {
				$none[] = $line + array( 'why' => 'לא נמצא תשלום בכרטיס סביב תאריך ההסכם' );
				continue;
			}
			$p    = $res['rows'][0];
			$line = $line + array( 'paid_on' => substr( (string) $p['date'], 0, 10 ), 'amount' => number_format( (int) $p['amount_minor'] / 100, 2 ), 'transaction' => (string) $p['index'] );
			if ( 'APPLE_PAY' === $p['broker'] ) {
				$apple[] = $line;
			} else {
				$card[] = $line + array( 'expiry' => (string) $p['expiry'] );
			}
		}
		return array( 'checked' => count( $rows ), 'apple_pay' => $apple, 'card' => $card, 'not_found' => $none );
	}

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
			// The stop stages as Pipedrive knows them: an empty list means the names did not match any stage.
			'stop_stages'      => ( static function () use ( $S ) {
				$names = array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $S::get( 'journey_stop_stages' ) ) ) ) );
				return array( 'names' => $names, 'stage_ids' => \Insiders\Collections\Integrations\Pipedrive\Client::stage_ids( $names ) );
			} )(),
			// Students who joined before the baseline and are in the dashboard: not in the journey unless journey_from_date reaches them.
			'before_baseline'  => $fd::before_baseline(),
			// Cases the upgrade repairs put back into the journey (an unrelated message had moved them to a person).
			'repaired'         => $Db::rows( 'SELECT entity_id AS case_id, occurred_at, reason FROM ' . $Db::t( 'audit_log' ) . " WHERE action = 'case.transition' AND reason LIKE %s ORDER BY id DESC LIMIT 50", '%[repair]' ),
			'names_from_wati'  => array( 'checked' => (int) $Db::value( 'SELECT COUNT(*) FROM ' . $Db::t( 'customers' ) . ' WHERE name_checked_at IS NOT NULL' ), 'changed' => (int) $Db::value( 'SELECT COUNT(*) FROM ' . $Db::t( 'audit_log' ) . " WHERE action = 'customer.name_from_wati'" ) ),
			'team_messages'    => (int) $Db::value( 'SELECT COUNT(*) FROM ' . $Db::t( 'messages' ) . " WHERE kind = 'team_message'" ),
			'collections_operators' => '' !== trim( (string) $S::get( 'wati_collections_operators' ) ) ? 'מוגדר' : 'לא מוגדר: הודעה שמוקלדת ב-WATI עוצרת כל תיק אחרי המועד (גם כשכתב אותה צוות אחר). מומלץ למלא בהגדרות',
			'finance_dashboard' => $fd::available() ? array( 'stale' => $fd::contract()['stale'], 'sync_ok_at' => $fd::contract()['sync_ok_at'], 'ledger_for_credit' => $fd::ledger_available() ) : 'לא נמצא',
			'cases'            => $Db::rows( 'SELECT phase, COALESCE(track, \'-\') AS track, workflow_state, COUNT(*) AS n FROM ' . $Db::t( 'cases' ) . " WHERE source_type = 'non_open_charge' GROUP BY phase, track, workflow_state" ),
			'last_run'         => Runner::beats()['journey'] ?? 'עוד לא רץ',
			// One real deal read back, to see that Pipedrive returns status, lost reason and labels as expected.
			// &deal=<id> checks one deal: what Pipedrive returns, what is stored, where its case stands.
			'deal_sample'      => ( static function () use ( $Db ) {
				$asked = (int) preg_replace( '/\D/', '', (string) ( $_GET['deal'] ?? '' ) );
				$deal  = $asked ?: (int) $Db::value( 'SELECT a.pipedrive_deal_id FROM ' . $Db::t( 'agreements' ) . " a WHERE a.type = 'beginner_program' AND a.pipedrive_deal_id IS NOT NULL ORDER BY a.id DESC LIMIT 1" );
				if ( ! $deal || ! \Insiders\Collections\Integrations\Pipedrive\Client::configured() ) {
					return 'אין עדיין דיל לבדיקה';
				}
				$r    = \Insiders\Collections\Integrations\Pipedrive\Client::deals( array( $deal ) );
				$case = $Db::row( 'SELECT c.id, c.track, c.track_step, c.workflow_state, a.deal_status, a.lost_reason, a.deal_checked_at FROM ' . $Db::t( 'cases' ) . ' c JOIN ' . $Db::t( 'agreements' ) . ' a ON a.id = c.agreement_id WHERE a.pipedrive_deal_id = %d ORDER BY c.id DESC LIMIT 1', $deal );
				return array(
					'deal'           => $deal,
					'as_read'        => $r['deals'][ $deal ] ?? ( 'לא חזר מפייפדרייב' . ( empty( $r['error'] ) ? '' : ': ' . $r['error'] ) ),
					'pipedrive_raw'  => \Insiders\Collections\Integrations\Pipedrive\Client::raw_deal_fields( $deal ),
					'products'       => \Insiders\Collections\Integrations\Pipedrive\Client::deal_products( array( $deal ) ),
					'excluded_products' => \Insiders\Collections\Domain\Journey::excluded_products(),
					'stored_and_case' => $case ?: 'אין תיק לדיל הזה',
				);
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
