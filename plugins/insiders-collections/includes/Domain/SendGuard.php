<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Runner;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-send checks (§9 תנאי בדיקה לפני כל שליחה), re-run at the moment of sending —
 * a message queued an hour ago is re-judged against the world as it is now.
 *
 * Blocker scopes:
 *  - case:      about this customer/case (balance, identity, quota...). Always blocks.
 *  - transient: time-based (window, gap). Blocks now, the action is rescheduled.
 *  - setup:     an integration or approval not ready. Blocks live sends; in
 *               display-only mode the simulation proceeds and records the gap,
 *               so the measurement phase shows what enforcement would have done.
 */
final class SendGuard {

	public static function mode(): string {
		if ( Settings::on( 'display_only' ) || Settings::on( 'kill_switch' ) || ! Settings::on( 'cap_customer_sends' ) ) {
			return 'simulate';
		}
		return 'live';
	}

	/**
	 * @param array $ctx keys: template (array), action_created_at (utc), is_reminder (bool), at (ts)
	 */
	public static function check( int $case_id, array $ctx = array() ): array {
		$b      = array();
		$add    = static function ( string $code, string $scope, string $msg ) use ( &$b ) {
			$b[] = array( 'code' => $code, 'scope' => $scope, 'message' => $msg );
		};
		$at       = $ctx['at'] ?? Clock::now();
		$mode     = self::mode();
		$case     = Workflow::get( $case_id );
		$customer = $case ? Customers::get( (int) $case['customer_id'] ) : null;
		if ( ! $case || ! $customer ) {
			$add( 'not_found', 'case', 'התיק לא נמצא' );
			return self::result( $b, $mode );
		}
		$is_reminder = $ctx['is_reminder'] ?? true;
		$tpl         = $ctx['template'] ?? null;
		$policy      = Policy::current();

		// Global switches and setup.
		if ( Settings::on( 'kill_switch' ) ) {
			$add( 'kill_switch', 'setup', 'מתג העצירה הכללי פעיל' );
		}
		if ( ! Settings::on( 'cap_customer_sends' ) ) {
			$add( 'sends_disabled', 'setup', 'יכולת המשלוח ללקוחות כבויה' );
		}
		if ( ! $policy ) {
			$add( 'policy_not_approved', 'setup', 'מדיניות הפנייה טרם אושרה' );
			$policy = Policy::effective_or_draft();
		}
		if ( $tpl && 'approved' !== $tpl['approval_state'] ) {
			$add( 'template_not_approved', 'setup', 'התבנית ' . $tpl['key'] . ' לא אושרה' );
		}
		if ( $tpl && 'whatsapp_template' === $tpl['channel'] && '' === (string) $tpl['provider_name'] ) {
			$add( 'template_unmapped', 'setup', 'התבנית לא משויכת לתבנית מאושרת ב-WATI' );
		}
		if ( ! Settings::has_secret( 'wati_token' ) || '' === (string) Settings::get( 'wati_api_base' ) ) {
			$add( 'wati_not_configured', 'setup', 'WATI לא מחובר' );
		}
		if ( Runner::integration_suspended( 'wati' ) ) {
			$add( 'wati_suspended', 'setup', 'חיבור WATI מושעה בגלל שגיאת הרשאה' );
		}

		// Case state.
		if ( $is_reminder && ! in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			$add( 'state', 'case', 'מצב התיק (' . Workflow::label( $case['workflow_state'] ) . ') אינו מאפשר תזכורת' );
		}
		if ( (int) $case['dispute_open'] ) {
			$add( 'dispute', 'case', 'קיימת מחלוקת פתוחה' );
		}
		if ( (int) $case['claims_account_opened'] ) {
			$add( 'account_opened_claim', 'case', 'הלקוח טוען שפתח חשבון — ממתין לבדיקה' );
		}
		$open_promise = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'promises' ) . " WHERE case_id = %d AND state IN ('requested','approved') AND promised_at >= %s", $case_id, Clock::today() );
		if ( $is_reminder && $open_promise && empty( $ctx['promise_followup'] ) ) {
			$add( 'promise_active', 'case', 'קיימת הבטחה או בקשת מועד בתוקף' );
		}

		// Money: fresh, approved, due, positive.
		$summary = Ledger::case_summary( $case_id );
		if ( $is_reminder && $summary['due_balance_minor'] <= 0 ) {
			$add( 'no_due_balance', 'case', 'אין יתרה שהגיע מועד פירעונה' );
		}
		if ( $summary['review_items'] > 0 ) {
			$add( 'item_in_review', 'case', 'יש פריט בבירור (החזר, הכחשה או בדיקה)' );
		}
		if ( $summary['mixed_currency'] ) {
			$add( 'mixed_currency', 'case', 'פריטים במטבעות שונים' );
		}
		$pending_events = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'inbox_events' ) . " WHERE provider = 'tranzila' AND processing_state IN ('received','processing')" );
		if ( $is_reminder && $pending_events > 0 && 'recurring_failure' === $case['source_type'] ) {
			$add( 'events_pending', 'transient', 'יש אירועי תשלום שטרם עובדו' );
		}
		if ( $is_reminder && 'recurring_failure' === $case['source_type'] && Runner::reconcile_stale() ) {
			$add( 'reconcile_stale', 'setup', 'ההתאמה מול טרנזילה לא עדכנית — עלולים לפנות למי שכבר שילם' );
		}

		// Identity and permission.
		if ( empty( $customer['phone_e164'] ) ) {
			$add( 'no_phone', 'case', 'אין מספר טלפון' );
		} elseif ( 'verified' !== $customer['contact_status'] ) {
			$add( 'identity', 'case', 'זהות או מספר לא אומתו (' . $customer['contact_status'] . ')' );
		}
		if ( Customers::shares_phone( $customer ) && 'verified' !== $customer['contact_status'] ) {
			$add( 'shared_phone', 'case', 'המספר משותף לכמה לקוחות' );
		}
		$perm = Customers::permission( (int) $customer['id'], 'whatsapp' );
		if ( true !== $perm ) {
			$add( false === $perm ? 'opted_out' : 'no_permission', 'case', false === $perm ? 'הלקוח ביקש להפסיק הודעות בערוץ' : 'אין הרשאת קשר מתועדת' );
		}
		if ( ! in_array( $customer['conversation_owner'], array( 'none', 'collections' ), true ) ) {
			$add( 'conversation_owner', 'case', 'השיחה בבעלות ' . $customer['conversation_owner'] );
		}

		// Conversation state.
		$unknown = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'out' AND delivery_state = 'unknown'", (int) $customer['id'] );
		if ( $unknown ) {
			$add( 'delivery_unknown', 'case', 'יש הודעה קודמת במצב מסירה לא ידוע' );
		}
		if ( ! empty( $ctx['action_created_at'] ) ) {
			$inbound_after = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'in' AND occurred_at > %s", (int) $customer['id'], $ctx['action_created_at'] );
			if ( $inbound_after ) {
				$add( 'customer_replied', 'case', 'הלקוח כתב אחרי שההודעה תוזמנה' );
			}
		}

		// Quota and timing (customer level, across all cases).
		if ( $is_reminder ) {
			$q = self::quota( (int) $customer['id'], $policy, $at, 'simulate' === $mode );
			if ( $q['count'] >= (int) $policy['quota_max'] ) {
				$add( 'quota', 'transient', 'הגיעה מכסת הפניות ללקוח (' . $q['count'] . ' בחלון)' );
			}
			if ( null !== $q['last_ts'] && $at - $q['last_ts'] < (int) $policy['min_gap_hours'] * HOUR_IN_SECONDS ) {
				$add( 'min_gap', 'transient', 'פחות מ־' . (int) $policy['min_gap_hours'] . ' שעות מהפנייה הקודמת' );
			}
			if ( ! Calendar::in_window( $at, $policy ) ) {
				$add( 'window', 'transient', 'מחוץ לחלון הפעילות' );
			}
		}

		// Template variables / service window are validated by the caller after rendering.
		return self::result( $b, $mode );
	}

	private static function result( array $b, string $mode ): array {
		$blocking = array_filter(
			$b,
			static fn( $x ) => 'live' === $mode || 'setup' !== $x['scope']
		);
		return array(
			'ok'          => ! $blocking,
			'mode'        => $mode,
			'blockers'    => array_values( $b ),
			'blocking'    => array_values( $blocking ),
			'transient'   => (bool) $blocking && ! array_filter( $blocking, static fn( $x ) => 'transient' !== $x['scope'] ),
		);
	}

	/** Proactive collection outreach in the rolling business-day window, plus imported history. */
	public static function quota( int $customer_id, array $policy, int $at, bool $include_simulated ): array {
		$from_date = Calendar::add_business_days( Clock::local_date( $at ), -( (int) $policy['quota_window_business_days'] - 1 ), $policy );
		$from_utc  = Clock::utc( Clock::local_to_ts( $from_date, '00:00' ) );
		$states    = $include_simulated ? "'accepted','sent','delivered','read','unknown','simulated'" : "'accepted','sent','delivered','read','unknown'";
		$rows      = Db::rows(
			'SELECT occurred_at FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'out' AND counts_toward_quota = 1 AND delivery_state IN ({$states}) AND occurred_at >= %s",
			$customer_id,
			$from_utc
		);
		$imported = Db::rows(
			'SELECT h.original_at AS occurred_at FROM ' . Db::t( 'imported_history' ) . ' h JOIN ' . Db::t( 'cases' ) . ' c ON c.id = h.case_id WHERE c.customer_id = %d AND h.counts_toward_quota = 1 AND h.original_at >= %s',
			$customer_id,
			$from_utc
		);
		$all  = array_merge( $rows, $imported );
		$last = Db::value(
			'SELECT MAX(occurred_at) FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'out' AND counts_toward_quota = 1 AND delivery_state IN ({$states})",
			$customer_id
		);
		$last_imp = Db::value( 'SELECT MAX(h.original_at) FROM ' . Db::t( 'imported_history' ) . ' h JOIN ' . Db::t( 'cases' ) . ' c ON c.id = h.case_id WHERE c.customer_id = %d AND h.counts_toward_quota = 1', $customer_id );
		$last_ts  = max( (int) Clock::ts( $last ), (int) Clock::ts( $last_imp ) );
		return array( 'count' => count( $all ), 'last_ts' => $last_ts > 0 ? $last_ts : null );
	}

	/** WhatsApp 24h service window, measured from the customer's last inbound message only. */
	public static function in_service_window( int $customer_id, ?int $at = null ): bool {
		$last = Db::value( 'SELECT MAX(occurred_at) FROM ' . Db::t( 'messages' ) . " WHERE customer_id = %d AND direction = 'in'", $customer_id );
		$ts   = Clock::ts( $last );
		return null !== $ts && ( $at ?? Clock::now() ) - $ts < DAY_IN_SECONDS - 300;
	}
}
