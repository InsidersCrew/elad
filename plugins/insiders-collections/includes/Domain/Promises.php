<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Payment promises (§10, §13). A customer's date is an operational follow-up
 * date, never a change to the accounting due date. Default: a rep approves it.
 * A broken promise earns one follow-up per policy; the next break goes to a rep.
 */
final class Promises {

	public static function request( int $case_id, string $date, ?int $amount_minor, string $source, ?int $message_id, string $note ): int {
		$case = Workflow::get( $case_id );
		$id   = Db::insert(
			'promises',
			array(
				'case_id'           => $case_id,
				'amount_minor'      => $amount_minor,
				'promised_at'       => $date,
				'state'             => 'requested',
				'source'            => $source,
				'source_message_id' => $message_id,
				'note'              => mb_substr( $note, 0, 500 ),
				'created_by'        => get_current_user_id() ?: null,
				'created_at'        => Clock::utc(),
			)
		);
		if ( Workflow::can( $case['workflow_state'], 'promise_pending' ) ) {
			Workflow::transition( $case_id, 'promise_pending', 'בקשה לשלם ב־' . $date, null, array(), $source );
		}
		if ( self::auto_approvable( $case_id, $date, $amount_minor ) ) {
			self::approve( $id, 'כלל דחייה אוטומטית' );
		} else {
			Tasks::open( 'promise_request:' . $id, 'promise_request', array( 'case_id' => $case_id, 'reason' => 'בקשה לשלם ב־' . $date . ( $note ? ': ' . mb_substr( $note, 0, 150 ) : '' ) ) );
		}
		return $id;
	}

	private static function auto_approvable( int $case_id, string $date, ?int $amount ): bool {
		$p = Policy::current();
		if ( ! $p || empty( $p['auto_postpone']['enabled'] ) ) {
			return false;
		}
		$rule  = $p['auto_postpone'];
		$days  = (int) floor( ( strtotime( $date ) - strtotime( Clock::today() ) ) / DAY_IN_SECONDS );
		$prior = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'promises' ) . " WHERE case_id = %d AND state IN ('approved','kept','broken')", $case_id );
		$due   = Ledger::case_summary( $case_id )['due_balance_minor'];
		return $days >= 0 && $days <= (int) $rule['max_days']
			&& ( (int) $rule['max_amount_minor'] <= 0 || $due <= (int) $rule['max_amount_minor'] )
			&& $prior < (int) $rule['max_count']
			&& ( $prior === 0 || ! empty( $rule['allow_if_prior_promises'] ) );
	}

	public static function approve( int $promise_id, string $note ): void {
		$p = Db::row( 'SELECT * FROM ' . Db::t( 'promises' ) . ' WHERE id = %d', $promise_id );
		if ( ! $p || 'requested' !== $p['state'] ) {
			throw new DomainError( 'invalid', 'הבקשה לא נמצאה או כבר טופלה', 422 );
		}
		Db::update( 'promises', array( 'state' => 'approved', 'approved_by' => get_current_user_id() ?: null ), array( 'id' => $promise_id ) );
		$case = Workflow::get( (int) $p['case_id'] );
		if ( Workflow::can( $case['workflow_state'], 'promise_hold' ) ) {
			Workflow::transition( (int) $p['case_id'], 'promise_hold', 'הבטחה אושרה עד ' . $p['promised_at'] . ( $note ? ' · ' . $note : '' ), null, array(), 'promise' );
		}
		Tasks::close_by_key( 'promise_request:' . $promise_id, 'אושר' );
		self::schedule_check( (int) $p['case_id'], $promise_id );
		Audit::log( 'promise.approve', 'promise', $promise_id, null, null, $note );
	}

	public static function reject( int $promise_id, string $note ): void {
		$p = Db::row( 'SELECT * FROM ' . Db::t( 'promises' ) . ' WHERE id = %d', $promise_id );
		if ( ! $p ) {
			throw new DomainError( 'not_found', 'הבקשה לא נמצאה', 404 );
		}
		Db::update( 'promises', array( 'state' => 'cancelled', 'resolved_at' => Clock::utc() ), array( 'id' => $promise_id ) );
		Tasks::close_by_key( 'promise_request:' . $promise_id, 'נדחה: ' . $note );
		Audit::log( 'promise.reject', 'promise', $promise_id, null, null, $note );
	}

	/** §9: no reminder before a promise date that falls on a non-business day; check after the date. */
	public static function schedule_check( int $case_id, int $promise_id ): void {
		$p      = Db::row( 'SELECT * FROM ' . Db::t( 'promises' ) . ' WHERE id = %d', $promise_id );
		$policy = Policy::effective_or_draft();
		$day    = Calendar::add_business_days( $p['promised_at'], 1, $policy );
		$at     = Calendar::next_slot( Clock::local_to_ts( $day, $policy['window_start'] ) + 2 * HOUR_IN_SECONDS, $policy );
		$case   = Workflow::get( $case_id );
		Scheduler::schedule( 'check_promise', $at, 'promise_check:' . $promise_id, $case_id, (int) $case['customer_id'], array( 'promise_id' => $promise_id ), (int) $policy['_version'] );
	}

	public static function execute_check( array $action ): string {
		$payload = (array) json_decode( (string) $action['payload'], true );
		$p       = Db::row( 'SELECT * FROM ' . Db::t( 'promises' ) . ' WHERE id = %d', (int) ( $payload['promise_id'] ?? 0 ) );
		if ( ! $p || 'approved' !== $p['state'] ) {
			return 'cancel:promise_not_active';
		}
		$case_id = (int) $p['case_id'];
		$summary = Ledger::case_summary( $case_id );
		$paid    = (int) Db::value(
			'SELECT COALESCE(SUM(a.amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = a.debt_item_id WHERE d.case_id = %d AND a.created_at >= %s',
			$case_id,
			$p['created_at']
		);
		$kept = $summary['due_balance_minor'] <= 0 || ( null !== $p['amount_minor'] && $paid >= (int) $p['amount_minor'] );
		if ( $kept ) {
			Db::update( 'promises', array( 'state' => 'kept', 'resolved_at' => Clock::utc() ), array( 'id' => (int) $p['id'] ) );
			if ( $summary['due_balance_minor'] > 0 ) {
				$case = Workflow::get( $case_id );
				Workflow::transition( $case_id, 'active', 'הבטחה קוימה, נותרה יתרה', null, array( 'sequence_step' => 0, 'sequence_started_at' => Clock::utc() ), 'promise' );
				Messaging::plan_next( $case_id );
			} else {
				Cases::maybe_close( $case_id );
			}
			return 'kept';
		}
		Db::update( 'promises', array( 'state' => 'broken', 'resolved_at' => Clock::utc() ), array( 'id' => (int) $p['id'] ) );
		$case   = Workflow::get( $case_id );
		$broken = (int) $case['broken_promises'] + 1;
		$policy = Policy::effective_or_draft();
		if ( $broken <= (int) $policy['broken_promise_followups'] ) {
			Workflow::transition( $case_id, 'active', 'הבטחה לא קוימה, הודעת המשך אחת', null, array( 'broken_promises' => $broken ), 'promise' );
			$at = Calendar::next_slot( Clock::now(), $policy );
			Scheduler::schedule( 'send_reminder', $at, 'promise_followup:' . $p['id'], $case_id, (int) $case['customer_id'], array( 'promise_followup' => true, 'step' => (int) $case['sequence_step'] ), (int) $policy['_version'] );
			return 'broken:followup';
		}
		Workflow::transition( $case_id, 'human_review', 'הבטחה הופרה שוב, העברה לנציג', null, array( 'broken_promises' => $broken ), 'promise' );
		Tasks::open( 'broken_promise:' . $p['id'], 'no_reply_call', array( 'case_id' => $case_id, 'reason' => 'הבטחה הופרה פעם נוספת' ) );
		return 'broken:human';
	}
}
