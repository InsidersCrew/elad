<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Integrations\Pipedrive\Client as Pipedrive;
use Insiders\Collections\Integrations\RevenueDashboard\Adapter;
use Insiders\Collections\Integrations\RevenueDashboard\FinanceDashboard;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The person-approved side of the beginner program:
 *  - the daily approval queue: students past the deadline (or who declined) wait
 *    here; the collections officer approves the list in one action, and each
 *    amount comes from the price rule, not from a typed number;
 *  - the credit window: an account opened within N days of paying opens a task;
 *    the officer refunds in Tranzila and records it here (never automatic);
 *  - the import of students who joined before the finance dashboard's baseline.
 */
final class ProgramCharges {

	private const WAITING_STATES = array( 'human_review', 'paused', 'payment_verification', 'promise_pending', 'promise_hold' );

	/** Cases waiting for a charge decision, each with what blocks it. */
	public static function queue(): array {
		$today = Clock::today();
		$ifd   = FinanceDashboard::available() ? FinanceDashboard::contract() : null;
		$rows  = Db::rows(
			'SELECT c.*, u.full_name, u.phone_e164, u.contact_status, u.pipedrive_person_id, a.account_open_deadline, a.signed_at, a.joined_at, a.type AS agr_type, a.pipedrive_deal_id, a.no_registration_fee, a.deal_checked_at, a.deal_status, a.lost_reason
			 FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'customers' ) . ' u ON u.id = c.customer_id JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id
			 WHERE c.phase = 'commitment' AND c.workflow_state <> 'closed' AND (a.account_open_deadline < %s OR c.track = 'declined')
			   AND NOT EXISTS (SELECT 1 FROM " . Db::t( 'debt_items' ) . ' d WHERE d.case_id = c.id AND d.approved_at IS NOT NULL)
			 ORDER BY a.account_open_deadline, c.id',
			$today
		);
		$grace = max( 0, (int) Settings::get( 'late_grace_days' ) );
		$out   = array();
		foreach ( $rows as $r ) {
			$sent  = Db::rows( 'SELECT template_key, occurred_at FROM ' . Db::t( 'messages' ) . " WHERE case_id = %d AND direction = 'out' AND counts_toward_quota = 1 AND delivery_state NOT IN ('failed','cancelled','draft') ORDER BY occurred_at", (int) $r['id'] );
			$quote = Pricing::quote( $r['signed_at'] ?: ( $r['joined_at'] ?: null ), (bool) (int) $r['no_registration_fee'] );
			$block = array();
			$warn  = array();
			if ( in_array( $r['workflow_state'], self::WAITING_STATES, true ) ) {
				$block[] = 'התיק ' . Workflow::label( $r['workflow_state'] );
			}
			if ( (int) $r['dispute_open'] ) {
				$block[] = 'מחלוקת פתוחה';
			}
			if ( (int) $r['claims_account_opened'] ) {
				$block[] = 'התלמיד כתב שפתח חשבון או שהפתיחה בתהליך, ממתין לבדיקה';
			}
			if ( ! $quote ) {
				$block[] = 'אין מחירון תקף בהגדרות';
			}
			if ( null === $ifd ) {
				$block[] = 'דשבורד ההכנסות לא זמין, אי אפשר לבדוק שהתלמיד לא פתח חשבון';
			} elseif ( $ifd['stale'] ) {
				$block[] = 'דשבורד ההכנסות לא עדכני, אי אפשר לדעת שהתלמיד לא פתח חשבון';
			}
			if ( 'late' === $r['track'] ) {
				$intro = array_values( array_filter( $sent, static fn( $m ) => 'l_intro' === $m['template_key'] ) )[0] ?? null;
				if ( ! $intro ) {
					$block[] = 'ההודעה על המועד שעבר עוד לא נשלחה';
				} else {
					$until = gmdate( 'Y-m-d', strtotime( Clock::local_date( (int) Clock::ts( $intro['occurred_at'] ) ) . ' UTC' ) + $grace * DAY_IN_SECONDS );
					if ( $until > $today ) {
						$block[] = 'ממתינים לתגובה עד ' . Journey::date_he( $until );
					}
				}
			}
			if ( ! $sent ) {
				$warn[] = 'לא נשלחה לתלמיד אף הודעת ליווי';
			}
			if ( empty( $r['phone_e164'] ) ) {
				$warn[] = 'אין מספר טלפון, הודעת התשלום לא תצא';
			} elseif ( 'verified' !== $r['contact_status'] ) {
				$warn[] = 'מספר הטלפון לא אומת';
			}
			if ( $r['pipedrive_deal_id'] && ! $r['deal_checked_at'] ) {
				$warn[] = 'הדיל עוד לא נקרא מפייפדרייב (תווית דמי הרישום לא נבדקה)';
			}
			$out[] = array(
				'case_id'      => (int) $r['id'],
				'version'      => (int) $r['version'],
				'name'         => $r['full_name'],
				'phone'        => $r['phone_e164'],
				'track'        => $r['track'],
				'track_label'  => Journey::TRACKS[ $r['track'] ] ?? '',
				'state'        => $r['workflow_state'],
				'signed_at'    => $r['signed_at'],
				'deadline'     => $r['account_open_deadline'],
				'deadline_he'  => Journey::date_he( (string) $r['account_open_deadline'] ),
				'before_deadline' => $r['account_open_deadline'] >= $today,
				'pay_requested' => 'pay_button' === $r['declined_source'],
				'deal_id'      => $r['pipedrive_deal_id'] ? (int) $r['pipedrive_deal_id'] : null,
				'no_registration_fee' => (bool) (int) $r['no_registration_fee'],
				'amount_minor' => $quote['due'] ?? null,
				'amount_text'  => $quote['text'] ?? '',
				'messages'     => count( $sent ),
				'last_message' => $sent ? Clock::display( end( $sent )['occurred_at'] ) : '',
				'blockers'     => $block,
				'warnings'     => $warn,
				'ready'        => ! $block,
			);
		}
		usort( $out, static fn( $a, $b ) => ( (int) $b['pay_requested'] <=> (int) $a['pay_requested'] ) ?: strcmp( (string) $a['deadline'], (string) $b['deadline'] ) ?: ( $a['case_id'] <=> $b['case_id'] ) );
		return array(
			'rows'  => $out,
			'ready' => count( array_filter( $out, static fn( $x ) => $x['ready'] ) ),
			'sync'  => $ifd ? array( 'ok_at' => $ifd['sync_ok_at'] ? Clock::display( $ifd['sync_ok_at'] ) : null, 'stale' => $ifd['stale'] ) : null,
			'basis' => 'לא נפתח חשבון עד המועד שבהסכם ההצטרפות (בדיקה מול דשבורד ההכנסות ב-' . Journey::date_he( $today ) . ')',
		);
	}

	/**
	 * One action for the day's list. Every case is checked again at this moment
	 * (the screen can be an hour old); what no longer qualifies is skipped with a reason.
	 */
	public static function approve( array $case_ids, string $basis ): array {
		if ( ! current_user_can( 'icol_approve_debt' ) ) {
			throw new DomainError( 'forbidden', 'אישור חיוב מותר לאחראי גבייה או מנהל', 403 );
		}
		if ( '' === trim( $basis ) ) {
			throw new DomainError( 'validation_failed', 'יש לתעד את בסיס החיוב', 400, array( 'basis' => 'חובה' ) );
		}
		FinanceDashboard::flush();
		$queue = array_column( self::queue()['rows'], null, 'case_id' );
		$done  = array();
		$skip  = array();
		foreach ( array_unique( array_map( 'intval', $case_ids ) ) as $id ) {
			$q = $queue[ $id ] ?? null;
			if ( ! $q ) {
				$skip[] = array( 'case_id' => $id, 'reason' => 'התיק כבר לא ממתין לאישור' );
				continue;
			}
			if ( ! $q['ready'] ) {
				$skip[] = array( 'case_id' => $id, 'reason' => implode( ' · ', $q['blockers'] ) );
				continue;
			}
			$case = Workflow::get( $id );
			$cust = Customers::get( (int) $case['customer_id'] );
			if ( $q['deal_id'] && $cust['pipedrive_person_id'] && FinanceDashboard::deal_resolution( (int) $cust['pipedrive_person_id'], $q['deal_id'] ) ) {
				$skip[] = array( 'case_id' => $id, 'reason' => 'לפי דשבורד ההכנסות התלמיד כבר פתח חשבון או שילם' );
				continue;
			}
			if ( $cust['pipedrive_person_id'] && $q['signed_at'] && $q['signed_at'] < FinanceDashboard::baseline() && FinanceDashboard::opened_since( array( (int) $cust['pipedrive_person_id'] ), (string) $q['signed_at'] ) ) {
				$skip[] = array( 'case_id' => $id, 'reason' => 'לפי ספר התנועות של הדשבורד התלמיד פתח חשבון אחרי ההסכם' );
				continue;
			}
			try {
				Db::transaction(
					function () use ( $id, $q, $basis ) {
						// The description reaches the student ("התשלום עבור ..."); the reason stays in the basis.
						$why  = $q['before_deadline'] ? 'התלמיד הודיע שלא יפתח חשבון' : 'לא נפתח חשבון עד ' . $q['deadline_he'];
						$item = Cases::insert_item( $id, array( 'amount_minor' => (int) $q['amount_minor'], 'due_at' => Clock::today(), 'description' => 'תוכנית הליווי למתחילים', 'evidence_ref' => $q['deal_id'] ? 'pipedrive:deal:' . $q['deal_id'] : '' ), 'ILS', false, $why . '. ' . $basis, 'program:' . $id );
						Db::update( 'debt_items', array( 'approved_by' => get_current_user_id(), 'approved_at' => Clock::utc() ), array( 'id' => $item ) );
						Ledger::recompute( $item );
						Audit::log( 'program_charge.approve', 'case', $id, null, array( 'item' => $item, 'amount' => (int) $q['amount_minor'], 'track' => $q['track'] ), $why . '. ' . $basis );
					}
				);
				Tasks::close_by_key( 'pay_request:' . $id, 'החיוב אושר' );
				if ( $q['pay_requested'] && Journey::send_now( $id, 'd_link' ) ) {
					// The student asked to pay: the details go now, as a reply, whatever the day.
				} elseif ( $q['before_deadline'] ) {
					Journey::plan_next( $id ); // the payment details, then the deadline day
				} else {
					Journey::to_charge( $id );
				}
			} catch ( \Throwable $e ) {
				// One student's failure never hides the others: the list reports it and goes on.
				$skip[] = array( 'case_id' => $id, 'reason' => 'שגיאה: ' . $e->getMessage() );
				continue;
			}
			$done[] = $id;
		}
		return array( 'approved' => count( $done ), 'case_ids' => $done, 'skipped' => $skip );
	}

	/**
	 * The charge for a student who pressed "אני רוצה לשלם", without a person, when the
	 * setting allows it. The same checks as the queue, run by the system: no earlier
	 * approved item, a price, and no resolution in the dashboard.
	 */
	public static function auto_charge( int $case_id, string $basis ): bool {
		$case = Workflow::get( $case_id );
		$agr  = $case ? Journey::agreement( $case ) : null;
		$cust = $case ? Customers::get( (int) $case['customer_id'] ) : null;
		if ( ! $case || ! $agr || ! $cust || 'commitment' !== $case['phase'] || 'closed' === $case['workflow_state'] ) {
			return false;
		}
		if ( Db::value( 'SELECT id FROM ' . Db::t( 'debt_items' ) . ' WHERE case_id = %d AND approved_at IS NOT NULL', $case_id ) ) {
			return true; // already approved by a person
		}
		$quote = Pricing::for_agreement( $agr );
		if ( ! $quote || (int) $case['dispute_open'] || (int) $case['claims_account_opened'] ) {
			return false;
		}
		if ( ! FinanceDashboard::available() || FinanceDashboard::contract()['stale'] ) {
			return false;
		}
		if ( $agr['pipedrive_deal_id'] && $cust['pipedrive_person_id'] && FinanceDashboard::deal_resolution( (int) $cust['pipedrive_person_id'], (int) $agr['pipedrive_deal_id'] ) ) {
			return false;
		}
		Db::transaction(
			function () use ( $case_id, $quote, $basis, $agr ) {
				$item = Cases::insert_item( $case_id, array( 'amount_minor' => (int) $quote['due'], 'due_at' => Clock::today(), 'description' => 'תוכנית הליווי למתחילים', 'evidence_ref' => $agr['pipedrive_deal_id'] ? 'pipedrive:deal:' . (int) $agr['pipedrive_deal_id'] : '' ), 'ILS', false, $basis, 'program:' . $case_id );
				Db::update( 'debt_items', array( 'approved_at' => Clock::utc() ), array( 'id' => $item ) ); // approved_by stays empty: the system, by the setting
				Ledger::recompute( $item );
				Audit::log( 'program_charge.auto', 'case', $case_id, null, array( 'item' => $item, 'amount' => (int) $quote['due'] ), $basis );
			}
		);
		return true;
	}

	/* --------------------------------------------------------- credit window */

	/**
	 * Daily: a student who paid and then opened an account within the credit window
	 * gets a task for the officer. IFD's commitment row cannot show this (it already
	 * closed as paid), so the ledger is read: a broker product after the payment date.
	 */
	public static function credit_watch(): array {
		if ( ! FinanceDashboard::available() || ! FinanceDashboard::ledger_available() ) {
			return array( 'ledger' => false );
		}
		$days = max( 1, (int) Settings::get( 'credit_window_days' ) );
		$rows = Db::rows(
			'SELECT c.id, c.customer_id, u.pipedrive_person_id, MIN(COALESCE(p.occurred_at, p.created_at)) AS paid_at, SUM(al.amount_minor) AS paid
			 FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'customers' ) . ' u ON u.id = c.customer_id
			 JOIN ' . Db::t( 'debt_items' ) . ' d ON d.case_id = c.id JOIN ' . Db::t( 'allocations' ) . ' al ON al.debt_item_id = d.id JOIN ' . Db::t( 'payments' ) . " p ON p.id = al.payment_id
			 WHERE c.source_type = 'non_open_charge' AND u.pipedrive_person_id IS NOT NULL
			 GROUP BY c.id, c.customer_id, u.pipedrive_person_id HAVING paid > 0 AND paid_at >= %s",
			Clock::utc( Clock::now() - ( $days + 2 ) * DAY_IN_SECONDS )
		);
		$found = 0;
		foreach ( $rows as $r ) {
			if ( Db::value( 'SELECT id FROM ' . Db::t( 'tasks' ) . ' WHERE task_key = %s', 'program_credit:' . $r['id'] ) ) {
				continue;
			}
			$paid_on = Clock::local_date( (int) Clock::ts( $r['paid_at'] ) );
			$opened  = FinanceDashboard::opened_since( array( (int) $r['pipedrive_person_id'] ), $paid_on )[ (int) $r['pipedrive_person_id'] ] ?? null;
			$until   = gmdate( 'Y-m-d', strtotime( $paid_on . ' UTC' ) + $days * DAY_IN_SECONDS );
			if ( ! $opened || $opened > $until ) {
				continue;
			}
			Tasks::open(
				'program_credit:' . $r['id'],
				'program_credit',
				array(
					'case_id'     => (int) $r['id'],
					'customer_id' => (int) $r['customer_id'],
					'priority'    => 'high',
					'reason'      => 'התלמיד שילם ' . Money::ils( (int) $r['paid'] ) . ' ב-' . Journey::date_he( $paid_on ) . ' ופתח חשבון ב-' . Journey::date_he( $opened ) . ', בתוך ' . $days . ' יום. לפי תנאי התוכנית מגיע זיכוי: לבדוק שהחשבון עומד בתנאים, לבצע זיכוי בטרנזילה, ולסמן בתיק "זיכוי בוצע".',
				)
			);
			++$found;
		}
		return array( 'ledger' => true, 'checked' => count( $rows ), 'credit_tasks' => $found );
	}

	/**
	 * The officer refunded in Tranzila: the payment is reversed, the charge is credited
	 * to zero (it is not owed any more), the case closes, and the rep removes the penalty
	 * product from the deal so the dashboard stops counting the revenue.
	 */
	public static function record_credit( int $case_id, string $evidence_ref, string $note ): array {
		if ( ! current_user_can( 'icol_verify_payment' ) ) {
			throw new DomainError( 'forbidden', 'רישום זיכוי מותר לאחראי גבייה או מנהל', 403 );
		}
		if ( '' === trim( $evidence_ref ) ) {
			throw new DomainError( 'validation_failed', 'יש לציין את אסמכתת הזיכוי בטרנזילה', 400, array( 'evidence_ref' => 'חובה' ) );
		}
		$case = Workflow::get( $case_id );
		if ( ! $case || 'non_open_charge' !== $case['source_type'] ) {
			throw new DomainError( 'not_found', 'התיק לא נמצא או שאינו של התוכנית למתחילים', 404 );
		}
		// Only this case's allocations: a payment that also settled another case of the student keeps that part.
		$allocs = Db::rows( 'SELECT a.id, a.payment_id, a.amount_minor FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = a.debt_item_id WHERE d.case_id = %d AND a.amount_minor > 0 AND a.reversal_of IS NULL AND NOT EXISTS (SELECT 1 FROM ' . Db::t( 'allocations' ) . ' r WHERE r.reversal_of = a.id)', $case_id );
		if ( ! $allocs ) {
			throw new DomainError( 'nothing_to_credit', 'אין בתיק תשלום שאפשר לזכות', 422 );
		}
		$reason = 'זיכוי לפי תנאי התוכנית: נפתח חשבון בתוך חלון הזיכוי' . ( '' !== trim( $note ) ? ' · ' . $note : '' );
		$total  = 0;
		Db::transaction(
			function () use ( $case_id, $allocs, $reason, $evidence_ref, &$total ) {
				foreach ( $allocs as $a ) {
					$total += (int) $a['amount_minor'];
					Ledger::reverse_allocation( (int) $a['id'], 'זיכוי: ' . $reason . ' (' . $evidence_ref . ')', false );
				}
				foreach ( array_unique( array_column( $allocs, 'payment_id' ) ) as $pid ) {
					// The payment is refunded as a whole only when nothing of it remains allocated anywhere.
					$left = (int) Db::value( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' WHERE payment_id = %d', (int) $pid );
					if ( $left <= 0 ) {
						Db::update( 'payments', array( 'status' => 'refunded' ), array( 'id' => (int) $pid ) );
					}
				}
				foreach ( Db::rows( 'SELECT id FROM ' . Db::t( 'debt_items' ) . ' WHERE case_id = %d', $case_id ) as $it ) {
					Ledger::clear_review( (int) $it['id'], 'זיכוי לפי תנאי התוכנית' );
					$bal = (int) Db::value( 'SELECT cached_balance_minor FROM ' . Db::t( 'debt_items' ) . ' WHERE id = %d', (int) $it['id'] );
					if ( $bal > 0 ) {
						Ledger::adjust( (int) $it['id'], 'credit', $bal, $reason, $evidence_ref );
					}
				}
			}
		);
		$case = Workflow::get( $case_id );
		if ( 'closed' !== $case['workflow_state'] && Workflow::can( $case['workflow_state'], 'closed' ) ) {
			Workflow::transition( $case_id, 'closed', $reason, null, array(), 'credit' );
		}
		Tasks::close_by_key( 'program_credit:' . $case_id, 'זיכוי בוצע: ' . $evidence_ref );
		$deal = (int) Db::value( 'SELECT a.pipedrive_deal_id FROM ' . Db::t( 'agreements' ) . ' a WHERE a.id = %d', (int) $case['agreement_id'] );
		Tasks::open( 'crm_credit:' . $case_id, 'crm_record', array( 'case_id' => $case_id, 'customer_id' => (int) $case['customer_id'], 'reason' => 'בוצע זיכוי של ' . Money::format( $total ) . ' ₪ לפי תנאי התוכנית. יש להסיר את מוצר חיוב אי-הפתיחה מהדיל' . ( $deal ? ' #' . $deal : '' ) . ' בפייפדרייב, כדי שדשבורד ההכנסות לא יספור את ההכנסה.' ) );
		Audit::log( 'program_charge.credit', 'case', $case_id, null, array( 'amount' => $total, 'evidence' => $evidence_ref ), $reason );
		return Cases::response( $case_id );
	}

	/* ---------------------------------------------------------------- import */

	private const HEADERS = array(
		'deal'     => array( 'deal', 'deal_id', 'דיל', 'מזהה דיל', 'מספר דיל', 'id' ),
		'signed'   => array( 'signed', 'signed_at', 'תאריך הסכם', 'תאריך חתימה', 'חתימה', 'הצטרפות', 'תאריך הצטרפות' ),
		'deadline' => array( 'deadline', 'מועד', 'מועד אחרון', 'דדליין' ),
		'name'     => array( 'name', 'שם', 'שם מלא', 'איש קשר' ),
		'phone'    => array( 'phone', 'טלפון', 'נייד' ),
		'email'    => array( 'email', 'מייל', 'אימייל', 'דוא"ל' ),
		'no_fee'   => array( 'no_fee', 'ללא דמי רישום', 'בלי דמי רישום' ),
	);

	private static function parse_date( string $v ): ?string {
		$v = trim( $v );
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m ) ) {
			[ $y, $mo, $d ] = array( (int) $m[1], (int) $m[2], (int) $m[3] );
		} elseif ( preg_match( '#^(\d{1,2})[/.](\d{1,2})[/.](\d{2,4})$#', $v, $m ) ) {
			[ $d, $mo, $y ] = array( (int) $m[1], (int) $m[2], (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3] );
		} else {
			return null;
		}
		return checkdate( $mo, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}

	/** Rows pasted from Excel (tabs) or CSV. A header row is optional; without one: deal, signed date[, phone, name]. */
	public static function parse_import( string $text ): array {
		$text  = preg_replace( '/^\x{FEFF}/u', '', $text ); // Excel's byte-order mark
		$lines = array_values( array_filter( preg_split( '/\R/', trim( $text ) ), static fn( $l ) => '' !== trim( $l ) ) );
		if ( ! $lines ) {
			return array();
		}
		$sep   = str_contains( $lines[0], "\t" ) ? "\t" : ( substr_count( $lines[0], ';' ) > substr_count( $lines[0], ',' ) ? ';' : ',' );
		$cells = array_map( static fn( $l ) => array_map( static fn( $c ) => trim( $c, " \"'" ), str_getcsv( $l, $sep ) ), $lines );
		$map   = array( 'deal' => 0, 'signed' => 1, 'phone' => 2, 'name' => 3 );
		if ( ! ctype_digit( $cells[0][0] ?? '' ) ) {
			$map = array();
			foreach ( $cells[0] as $i => $h ) {
				$h = mb_strtolower( trim( $h ) );
				foreach ( self::HEADERS as $k => $names ) {
					if ( ! isset( $map[ $k ] ) && in_array( $h, $names, true ) ) {
						$map[ $k ] = $i;
					}
				}
			}
			array_shift( $cells );
		}
		$out = array();
		foreach ( $cells as $n => $c ) {
			$get    = static fn( string $k ) => isset( $map[ $k ] ) ? trim( (string) ( $c[ $map[ $k ] ] ?? '' ) ) : '';
			$deal   = $get( 'deal' );
			$signed = self::parse_date( $get( 'signed' ) );
			$dl     = '' !== $get( 'deadline' ) ? self::parse_date( $get( 'deadline' ) ) : null;
			$errors = array();
			if ( ! ctype_digit( $deal ) ) {
				$errors[] = 'מספר דיל חסר או לא תקין';
			}
			if ( ! $signed ) {
				$errors[] = 'תאריך הסכם חסר או לא תקין';
			}
			if ( '' !== $get( 'deadline' ) && ! $dl ) {
				$errors[] = 'מועד לא תקין';
			}
			$out[] = array(
				'line'     => $n + 1,
				'deal_id'  => ctype_digit( $deal ) ? (int) $deal : null,
				'signed'   => $signed,
				'deadline' => $dl ?: ( $signed ? gmdate( 'Y-m-d', strtotime( $signed . ' UTC' ) + FinanceDashboard::window_days() * DAY_IN_SECONDS ) : null ),
				'name'     => $get( 'name' ),
				'phone'    => $get( 'phone' ),
				'email'    => $get( 'email' ),
				'no_fee'   => in_array( mb_strtolower( $get( 'no_fee' ) ), array( '1', 'כן', 'yes', 'true', 'v', '✓' ), true ),
				'errors'   => $errors,
			);
		}
		return $out;
	}

	/**
	 * Dry run (commit = false) or import. The deal is read from Pipedrive (person and
	 * label), each row is checked against open cases and against IFD's ledger (an
	 * account opened after signing), and the valid rows become candidates that the
	 * hourly job enrolls, a first batch right away.
	 */
	public static function import( string $text, bool $commit ): array {
		if ( ! current_user_can( 'icol_approve_debt' ) ) {
			throw new DomainError( 'forbidden', 'ייבוא מותר לאחראי גבייה או מנהל', 403 );
		}
		$rows = self::parse_import( $text );
		if ( count( $rows ) > 500 ) {
			throw new DomainError( 'validation_failed', 'עד 500 שורות בכל ייבוא', 400 );
		}
		$deals = array();
		$ids   = array_filter( array_column( $rows, 'deal_id' ) );
		if ( $ids ) {
			if ( ! Pipedrive::configured() ) {
				throw new DomainError( 'pipedrive_required', 'כדי לייבא צריך חיבור לפייפדרייב (טוקן במסך החיבורים)', 422 );
			}
			$res = Pipedrive::deals( $ids );
			if ( ! $res['ok'] ) {
				throw new DomainError( 'pipedrive_failed', 'פייפדרייב לא החזיר את הדילים: ' . ( $res['error'] ?: 'שגיאה לא ידועה' ) . '. לבדוק את הטוקן במסך החיבורים ולנסות שוב.', 502 );
			}
			$deals = $res['deals'];
		}
		$label = Pipedrive::label_id( (string) Settings::get( 'no_registration_label' ) );
		foreach ( $rows as &$r ) {
			$d = $r['deal_id'] ? ( $deals[ $r['deal_id'] ] ?? null ) : null;
			if ( $r['deal_id'] && ! $d ) {
				$r['errors'][] = 'הדיל לא נמצא בפייפדרייב';
			}
			$r['person_id'] = $d['person_id'] ?? null;
			if ( $d && ! $r['person_id'] ) {
				$r['errors'][] = 'לדיל אין איש קשר';
			}
			$r['no_fee'] = $r['no_fee'] || ( $d && null !== $label && in_array( $label, $d['label_ids'], true ) );
			$q           = Pricing::quote( $r['signed'], $r['no_fee'] );
			$r['amount_text'] = $q['text'] ?? '';
			$r['status'] = $r['errors'] ? 'invalid' : 'new';
			if ( 'new' === $r['status'] && Db::value( 'SELECT c.id FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id WHERE a.pipedrive_deal_id = %d AND c.workflow_state <> 'closed'", $r['deal_id'] ) ) {
				$r['status'] = 'exists';
			}
			if ( 'new' === $r['status'] && $r['person_id'] && Db::value( 'SELECT id FROM ' . Db::t( 'program_candidates' ) . ' WHERE candidate_key = %s', 'pd' . $r['person_id'] . ':' . $r['deal_id'] ) ) {
				$r['status'] = 'queued'; // imported or found before, waiting for (or past) enrolment
			}
			if ( 'new' === $r['status'] && $r['person_id'] && $r['signed'] && FinanceDashboard::opened_since( array( (int) $r['person_id'] ), $r['signed'] ) ) {
				$r['status'] = 'opened';
			}
			if ( 'new' === $r['status'] && $r['person_id'] && FinanceDashboard::deal_resolution( (int) $r['person_id'], (int) $r['deal_id'] ) ) {
				$r['status'] = 'opened';
			}
		}
		unset( $r );
		$summary = array_count_values( array_column( $rows, 'status' ) );
		if ( ! $commit ) {
			return array( 'rows' => $rows, 'summary' => $summary, 'committed' => false );
		}
		$queued = 0;
		foreach ( $rows as $r ) {
			if ( 'new' !== $r['status'] ) {
				continue;
			}
			$cand = array(
				'source'              => 'import',
				'pipedrive_person_id' => (int) $r['person_id'],
				'pipedrive_deal_id'   => (int) $r['deal_id'],
				'name'                => $r['name'],
				'phone'               => $r['phone'],
				'email'               => $r['email'],
				'joined_at'           => $r['signed'],
				'deadline'            => $r['deadline'],
				'effective_deadline'  => $r['deadline'],
				'no_registration_fee' => $r['no_fee'],
				'agreement_ref'       => 'Pipedrive deal ' . $r['deal_id'],
			);
			$key = Adapter::candidate_key( $cand );
			if ( Db::value( 'SELECT id FROM ' . Db::t( 'program_candidates' ) . ' WHERE candidate_key = %s', $key ) ) {
				continue;
			}
			Db::insert( 'program_candidates', array( 'source' => 'import', 'pipedrive_person_id' => (int) $r['person_id'], 'pipedrive_deal_id' => (int) $r['deal_id'], 'candidate_key' => $key, 'deadline' => $r['deadline'], 'status' => 'new', 'snapshot' => wp_json_encode( $cand, JSON_UNESCAPED_UNICODE ), 'decided_by' => get_current_user_id() ?: null, 'created_at' => Clock::utc(), 'updated_at' => Clock::utc() ) );
			++$queued;
		}
		Audit::log( 'program.import', 'system', 0, null, array( 'rows' => count( $rows ), 'queued' => $queued ), '' );
		$enrolled = self::enroll_imported( max( 1, (int) Settings::get( 'journey_batch' ) ) );
		return array( 'rows' => $rows, 'summary' => $summary, 'committed' => true, 'queued' => $queued, 'enrolled' => $enrolled );
	}

	/** Imported candidates waiting for enrollment (also run by the hourly job). */
	public static function enroll_imported( int $limit ): int {
		$n = 0;
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'program_candidates' ) . " WHERE source = 'import' AND status IN ('new','enrolling') ORDER BY id LIMIT %d", $limit ) as $pc ) {
			$r     = (array) json_decode( (string) $pc['snapshot'], true );
			$track = ( $r['effective_deadline'] ?? '' ) < Clock::today() ? 'late' : 'reach';
			if ( Journey::enroll( $r, $track, 'import' ) ) {
				++$n;
			}
		}
		return $n;
	}
}
