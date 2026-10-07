<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Receipts (§8). A payment is unique per (provider, terminal, transaction id):
 * the same transaction from a webhook and from a report is one payment (AT20),
 * and a status change never creates a second receipt.
 *
 * A screenshot, "שילמתי", a success-page redirect or a read receipt are not
 * receipts — only a verified provider transaction or an officer-verified reference.
 */
final class Payments {

	/** @return array{0:int,1:bool} [payment_id, created] */
	public static function record( array $d ): array {
		$provider = (string) $d['provider'];
		$terminal = (string) ( $d['terminal'] ?? '' );
		$txn      = (string) $d['transaction_id'];
		$existing = Db::row( 'SELECT id FROM ' . Db::t( 'payments' ) . ' WHERE provider = %s AND terminal = %s AND transaction_id = %s', $provider, $terminal, $txn );
		if ( $existing ) {
			return array( (int) $existing['id'], false );
		}
		try {
			$id = Db::insert(
				'payments',
				array(
					'provider'            => $provider,
					'terminal'            => $terminal,
					'transaction_id'      => $txn,
					'customer_id'         => $d['customer_id'] ?? null,
					'recurring_order_id'  => $d['recurring_order_id'] ?? null,
					'payment_request_id'  => $d['payment_request_id'] ?? null,
					'amount_minor'        => (int) $d['amount_minor'],
					'currency'            => (string) $d['currency'],
					'status'              => $d['status'] ?? 'verified',
					'tran_type'           => $d['tran_type'] ?? 'charge',
					'origin'              => $d['origin'] ?? 'manual',
					'payment_method'      => $d['payment_method'] ?? 'unknown',
					'has_reusable_token'  => ! empty( $d['has_reusable_token'] ) ? 1 : 0,
					'card_last4'          => $d['card_last4'] ?? null,
					'card_expiry'         => $d['card_expiry'] ?? null,
					'cycle_ref'           => $d['cycle_ref'] ?? null,
					'occurred_at'         => $d['occurred_at'] ?? Clock::utc(),
					'verified_at'         => ( $d['status'] ?? 'verified' ) === 'verified' ? Clock::utc() : null,
					'verified_by'         => $d['verified_by'] ?? null,
					'verification_source' => $d['verification_source'] ?? null,
					'evidence_ref'        => $d['evidence_ref'] ?? null,
					'document_ref'        => $d['document_ref'] ?? null,
					'created_at'          => Clock::utc(),
				)
			);
		} catch ( \Insiders\Collections\Support\DbError $e ) {
			if ( $e->duplicate ) {
				// Concurrent delivery won the race; the unique key is the guarantee.
				return array( (int) Db::value( 'SELECT id FROM ' . Db::t( 'payments' ) . ' WHERE provider = %s AND terminal = %s AND transaction_id = %s', $provider, $terminal, $txn ), false );
			}
			throw $e;
		}
		Audit::log( 'payment.record', 'payment', $id, null, array( 'amount' => (int) $d['amount_minor'], 'provider' => $provider, 'terminal' => $terminal ), (string) ( $d['verification_source'] ?? '' ) );
		return array( $id, true );
	}

	/**
	 * Officer-verified external payment (bank transfer, Bit, cash...). Explicit
	 * allocations only. Overpayment stays unallocated for review.
	 */
	public static function manual_verification( array $d ): array {
		if ( ! current_user_can( 'icol_verify_payment' ) ) {
			throw new DomainError( 'forbidden', 'אימות תקבול חיצוני מותר לאחראי גבייה או מנהל', 403 );
		}
		$amount = isset( $d['amount_minor'] ) ? (int) $d['amount_minor'] : Money::parse( $d['amount'] ?? '' );
		$errors = array();
		if ( null === $amount || $amount <= 0 ) {
			$errors['amount'] = 'סכום חיובי חובה';
		}
		if ( '' === trim( (string) ( $d['evidence_ref'] ?? '' ) ) ) {
			$errors['evidence_ref'] = 'אסמכתה חובה (מספר העברה, אישור בנק)';
		}
		if ( empty( $d['customer_id'] ) ) {
			$errors['customer_id'] = 'חובה';
		}
		$currency = Money::normalize_currency( $d['currency'] ?? 'ILS' );
		if ( ! $currency ) {
			$errors['currency'] = 'מטבע לא נתמך';
		}
		$allocs = (array) ( $d['allocations'] ?? array() );
		$sum    = 0;
		foreach ( $allocs as $i => $a ) {
			$amt = isset( $a['amount_minor'] ) ? (int) $a['amount_minor'] : Money::parse( $a['amount'] ?? '' );
			if ( null === $amt || $amt <= 0 || empty( $a['debt_item_id'] ) ) {
				$errors[ "allocations.$i" ] = 'שיוך לא תקין';
			}
			$sum += (int) $amt;
		}
		if ( $amount && $sum > $amount ) {
			$errors['allocations'] = 'סכום השיוכים עובר את סכום התקבול';
		}
		if ( $errors ) {
			throw new DomainError( 'validation_failed', 'יש שדות שדורשים תיקון', 400, $errors );
		}
		return Db::transaction(
			function () use ( $d, $amount, $currency, $allocs ) {
				[ $pid, $created ] = self::record(
					array(
						'provider'            => 'manual',
						'terminal'            => (string) ( $d['method'] ?? 'other' ),
						'transaction_id'      => mb_substr( trim( (string) $d['evidence_ref'] ), 0, 64 ),
						'customer_id'         => (int) $d['customer_id'],
						'amount_minor'        => $amount,
						'currency'            => $currency,
						'origin'              => 'manual',
						'payment_method'      => in_array( $d['method'] ?? '', array( 'bank_transfer', 'bit', 'cash', 'check', 'card_other', 'other' ), true ) ? $d['method'] : 'other',
						'occurred_at'         => ! empty( $d['paid_at'] ) ? Clock::utc( Clock::local_to_ts( substr( $d['paid_at'], 0, 10 ), '12:00' ) ) : Clock::utc(),
						'verified_by'         => get_current_user_id(),
						'verification_source' => 'officer',
						'evidence_ref'        => mb_substr( (string) $d['evidence_ref'], 0, 255 ),
						'document_ref'        => mb_substr( (string) ( $d['document_ref'] ?? '' ), 0, 190 ) ?: null,
					)
				);
				if ( ! $created ) {
					throw new DomainError( 'duplicate_payment', 'תקבול עם אותה אסמכתה כבר נרשם', 409 );
				}
				foreach ( $allocs as $a ) {
					$amt = isset( $a['amount_minor'] ) ? (int) $a['amount_minor'] : (int) Money::parse( $a['amount'] );
					Ledger::allocate( $pid, (int) $a['debt_item_id'], $amt, 'שיוך ידני באימות תקבול' );
				}
				self::after_payment( $pid );
				return array( 'payment_id' => $pid, 'unallocated_minor' => Ledger::payment_unallocated( $pid ) );
			}
		);
	}

	/**
	 * Follow-up after any verified payment: void reminders for what was paid,
	 * move cases on, flag leftovers, open card tasks, confirm to the customer.
	 */
	/**
	 * @param bool $expect_allocation false for routine standing-order successes with no
	 *                                open item: an unallocated routine installment is not an anomaly.
	 */
	public static function after_payment( int $payment_id, bool $expect_allocation = true ): void {
		$p     = Ledger::payment( $payment_id );
		$cases = Db::rows(
			'SELECT DISTINCT d.case_id FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = a.debt_item_id WHERE a.payment_id = %d',
			$payment_id
		);
		foreach ( $cases as $r ) {
			$case_id = (int) $r['case_id'];
			Scheduler::cancel_for_case( $case_id, 'payment:' . $payment_id );
			$case    = Workflow::get( $case_id );
			$summary = Ledger::case_summary( $case_id );
			$contacted = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) . " WHERE case_id = %d AND direction IN ('out','in') AND channel = 'whatsapp' AND delivery_state <> 'draft'", $case_id );
			if ( $summary['due_balance_minor'] <= 0 && 0 === $summary['review_items'] ) {
				Cases::maybe_close( $case_id );
				Tasks::close_by_key( 'no_reply:' . $case_id, 'שולם' );
			} elseif ( in_array( $case['workflow_state'], array( 'active', 'waiting_reply', 'payment_verification' ), true ) ) {
				Workflow::transition( $case_id, 'active', 'נקלט תשלום חלקי, היתרה ' . Money::format( $summary['due_balance_minor'] ), null, array(), 'payment' );
				Messaging::plan_next( $case_id );
			}
			$amount_here = (int) Db::value( 'SELECT COALESCE(SUM(a.amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = a.debt_item_id WHERE a.payment_id = %d AND d.case_id = %d', $payment_id, $case_id );
			if ( $contacted > 0 && $amount_here > 0 ) {
				Messaging::payment_confirmation( $case_id, $amount_here );
			}
		}
		$left = Ledger::payment_unallocated( $payment_id );
		if ( $left > 0 && 'verified' === $p['status'] && $expect_allocation ) {
			$type = $cases ? 'overpayment' : 'payment_without_debt';
			Exceptions::open(
				$type . ':' . $payment_id,
				$type,
				$cases ? 'medium' : 'high',
				( $cases ? 'תשלום עודף' : 'תקבול ללא שיוך' ) . ': ' . Money::format( $left ) . ' ₪ לא הוקצו',
				array( 'entity_type' => 'payment', 'entity_id' => $payment_id, 'customer_id' => $p['customer_id'] ? (int) $p['customer_id'] : null )
			);
		}
		CardTasks::maybe_create( $payment_id );
	}

	/** Refund / chargeback (AT37): linked reversal rows, review state, no automatic outreach. */
	public static function reverse( int $payment_id, string $kind, string $reason ): void {
		$p = Ledger::payment( $payment_id );
		if ( ! $p ) {
			throw new DomainError( 'not_found', 'התקבול לא נמצא', 404 );
		}
		$allocs = Db::rows( 'SELECT a.*, d.case_id FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = a.debt_item_id WHERE a.payment_id = %d AND a.reversal_of IS NULL AND a.amount_minor > 0 AND NOT EXISTS (SELECT 1 FROM ' . Db::t( 'allocations' ) . ' r WHERE r.reversal_of = a.id)', $payment_id );
		Db::update( 'payments', array( 'status' => 'refund' === $kind ? 'refunded' : 'chargeback' ), array( 'id' => $payment_id ) );
		foreach ( $allocs as $a ) {
			Ledger::reverse_allocation( (int) $a['id'], ( 'refund' === $kind ? 'החזר' : 'הכחשה' ) . ': ' . $reason, true );
			$case = Workflow::get( (int) $a['case_id'] );
			Scheduler::cancel_for_case( (int) $a['case_id'], 'reversal' );
			if ( Workflow::can( $case['workflow_state'], 'human_review' ) ) {
				Workflow::transition( (int) $a['case_id'], 'human_review', ( 'refund' === $kind ? 'החזר' : 'הכחשת עסקה' ) . ', נדרשת בדיקת אחראי לפני פנייה', null, array(), 'reversal' );
			}
		}
		Exceptions::open( 'reversal:' . $payment_id, 'refund_or_chargeback', 'high', ( 'refund' === $kind ? 'החזר' : 'הכחשה' ) . ' על תקבול ' . Money::format( (int) $p['amount_minor'] ) . ' ₪', array( 'entity_type' => 'payment', 'entity_id' => $payment_id, 'customer_id' => $p['customer_id'] ? (int) $p['customer_id'] : null ) );
	}
}
