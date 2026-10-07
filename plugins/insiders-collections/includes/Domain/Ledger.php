<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Balance of a debt item = original (once approved) + Σ adjustments (signed)
 *                          − Σ allocations (signed; reversals are negative rows).
 *
 * Nothing edits a balance directly. The cached balance is updated in the same
 * transaction as the movement, under a row lock, and reconciled periodically.
 */
final class Ledger {

	/** Adjustment types and the sign of their effect on the balance. */
	public const ADJUSTMENTS = array(
		'credit'              => -1, // זיכוי
		'write_off'           => -1, // מחיקה מאושרת
		'correction_decrease' => -1,
		'correction_increase' => 1,
	);

	public static function item( int $id, bool $lock = false ): ?array {
		return Db::row( 'SELECT * FROM ' . Db::t( 'debt_items' ) . ' WHERE id = %d' . ( $lock ? ' FOR UPDATE' : '' ), $id );
	}

	/** Recompute balance + finance state from movements. Must run inside a transaction. */
	public static function recompute( int $item_id ): array {
		$item = self::item( $item_id, true );
		if ( ! $item ) {
			throw new DomainError( 'not_found', 'פריט חוב לא נמצא', 404 );
		}
		$adj   = (int) Db::value( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . Db::t( 'adjustments' ) . ' WHERE debt_item_id = %d', $item_id );
		$alloc = (int) Db::value( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' WHERE debt_item_id = %d', $item_id );

		$approved = null !== $item['approved_at'];
		$balance  = $approved ? (int) $item['original_amount_minor'] + $adj - $alloc : 0;
		$state    = self::derive_state( $item, $approved, $balance, $alloc );

		$changed = (int) $item['cached_balance_minor'] !== $balance || $item['finance_state'] !== $state;
		if ( $changed ) {
			Db::update(
				'debt_items',
				array(
					'cached_balance_minor' => $balance,
					'finance_state'        => $state,
					'balance_version'      => (int) $item['balance_version'] + 1,
					'settled_at'           => in_array( $state, array( 'settled', 'cancelled' ), true ) ? ( $item['settled_at'] ?? Clock::utc() ) : null,
					'updated_at'           => Clock::utc(),
				),
				array( 'id' => $item_id )
			);
			$item['cached_balance_minor'] = $balance;
			$item['finance_state']        = $state;
			$item['balance_version']      = (int) $item['balance_version'] + 1;
		}
		return $item;
	}

	private static function derive_state( array $item, bool $approved, int $balance, int $alloc ): string {
		if ( ! $approved ) {
			return 'draft';
		}
		if ( ! empty( $item['review_reason'] ) ) {
			return 'review';
		}
		if ( $balance <= 0 ) {
			return $alloc > 0 ? 'settled' : 'cancelled';
		}
		if ( $item['due_at'] > Clock::today() ) {
			return 'not_due';
		}
		return $alloc > 0 ? 'partially_paid' : 'open';
	}

	public static function payment( int $id, bool $lock = false ): ?array {
		return Db::row( 'SELECT * FROM ' . Db::t( 'payments' ) . ' WHERE id = %d' . ( $lock ? ' FOR UPDATE' : '' ), $id );
	}

	public static function payment_unallocated( int $payment_id ): int {
		$p = self::payment( $payment_id );
		if ( ! $p ) {
			return 0;
		}
		$alloc = (int) Db::value( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' WHERE payment_id = %d', $payment_id );
		return (int) $p['amount_minor'] - $alloc;
	}

	/**
	 * Allocate part of a verified payment to a debt item. Locks payment then item
	 * (always in that order — the same order everywhere prevents deadlocks).
	 */
	public static function allocate( int $payment_id, int $item_id, int $amount, string $reason ): int {
		return Db::transaction(
			function () use ( $payment_id, $item_id, $amount, $reason ) {
				$p = self::payment( $payment_id, true );
				if ( ! $p || 'verified' !== $p['status'] ) {
					throw new DomainError( 'payment_not_verified', 'אפשר לשייך רק תקבול מאומת', 422 );
				}
				$item = self::recompute( $item_id );
				if ( $p['currency'] !== $item['currency'] ) {
					throw new DomainError( 'currency_mismatch', 'מטבע התקבול שונה ממטבע החוב', 422 );
				}
				if ( null === $item['approved_at'] ) {
					throw new DomainError( 'item_not_approved', 'אי אפשר לשייך לחוב שלא אושר', 422 );
				}
				$unallocated = self::payment_unallocated( $payment_id );
				if ( $amount <= 0 || $amount > $unallocated ) {
					throw new DomainError( 'allocation_exceeds_payment', 'סכום השיוך עובר את יתרת התקבול', 422 );
				}
				if ( $amount > (int) $item['cached_balance_minor'] ) {
					throw new DomainError( 'allocation_exceeds_balance', 'סכום השיוך עובר את יתרת החוב', 422 );
				}
				$id = Db::insert(
					'allocations',
					array(
						'payment_id'   => $payment_id,
						'debt_item_id' => $item_id,
						'amount_minor' => $amount,
						'reason'       => mb_substr( $reason, 0, 255 ),
						'created_by'   => get_current_user_id() ?: null,
						'created_at'   => Clock::utc(),
					)
				);
				$after = self::recompute( $item_id );
				Audit::log( 'allocation.create', 'debt_item', $item_id, array( 'balance' => (int) $item['cached_balance_minor'] ), array( 'balance' => (int) $after['cached_balance_minor'], 'allocation_id' => $id, 'payment_id' => $payment_id ), $reason );
				do_action( 'icol_payment_allocated', $id, $payment_id, $item_id, $amount );
				return $id;
			}
		);
	}

	/** Reverse an allocation with a linked negative row (refund, chargeback, wrong match). */
	public static function reverse_allocation( int $allocation_id, string $reason, bool $flag_review ): int {
		return Db::transaction(
			function () use ( $allocation_id, $reason, $flag_review ) {
				$a = Db::row( 'SELECT * FROM ' . Db::t( 'allocations' ) . ' WHERE id = %d FOR UPDATE', $allocation_id );
				if ( ! $a || null !== $a['reversal_of'] ) {
					throw new DomainError( 'invalid_reversal', 'שיוך לא קיים או שהוא עצמו היפוך', 422 );
				}
				self::payment( (int) $a['payment_id'], true );
				self::item( (int) $a['debt_item_id'], true );
				$id = Db::insert(
					'allocations',
					array(
						'payment_id'   => (int) $a['payment_id'],
						'debt_item_id' => (int) $a['debt_item_id'],
						'amount_minor' => -1 * (int) $a['amount_minor'],
						'reversal_of'  => $allocation_id,
						'reason'       => mb_substr( $reason, 0, 255 ),
						'created_by'   => get_current_user_id() ?: null,
						'created_at'   => Clock::utc(),
					)
				);
				if ( $flag_review ) {
					Db::update( 'debt_items', array( 'review_reason' => mb_substr( $reason, 0, 255 ) ), array( 'id' => (int) $a['debt_item_id'] ) );
				}
				self::recompute( (int) $a['debt_item_id'] );
				Audit::log( 'allocation.reverse', 'debt_item', (int) $a['debt_item_id'], null, array( 'reversal_id' => $id, 'of' => $allocation_id ), $reason );
				return $id;
			}
		);
	}

	/** Credit / write-off / correction — an approved, evidenced event; never a field edit. */
	public static function adjust( int $item_id, string $type, int $amount_abs, string $reason, string $evidence_ref ): int {
		if ( ! isset( self::ADJUSTMENTS[ $type ] ) ) {
			throw new DomainError( 'invalid_adjustment', 'סוג התאמה לא מוכר', 400, array( 'type' => 'לא מוכר' ) );
		}
		if ( $amount_abs <= 0 ) {
			throw new DomainError( 'invalid_amount', 'הסכום חייב להיות חיובי', 400, array( 'amount' => 'חיובי' ) );
		}
		if ( '' === trim( $reason ) ) {
			throw new DomainError( 'reason_required', 'נדרשת סיבה', 400, array( 'reason' => 'חובה' ) );
		}
		return Db::transaction(
			function () use ( $item_id, $type, $amount_abs, $reason, $evidence_ref ) {
				$item = self::recompute( $item_id );
				$signed = self::ADJUSTMENTS[ $type ] * $amount_abs;
				if ( $signed < 0 && $amount_abs > (int) $item['cached_balance_minor'] ) {
					throw new DomainError( 'adjustment_exceeds_balance', 'ההתאמה עוברת את היתרה', 422 );
				}
				$id = Db::insert(
					'adjustments',
					array(
						'debt_item_id' => $item_id,
						'type'         => $type,
						'amount_minor' => $signed,
						'reason'       => mb_substr( $reason, 0, 500 ),
						'evidence_ref' => mb_substr( $evidence_ref, 0, 255 ),
						'approved_by'  => get_current_user_id(),
						'created_at'   => Clock::utc(),
					)
				);
				$after = self::recompute( $item_id );
				Audit::log( 'adjustment.create', 'debt_item', $item_id, array( 'balance' => (int) $item['cached_balance_minor'] ), array( 'balance' => (int) $after['cached_balance_minor'], 'type' => $type ), $reason );
				return $id;
			}
		);
	}

	public static function clear_review( int $item_id, string $note ): void {
		Db::transaction(
			function () use ( $item_id, $note ) {
				self::item( $item_id, true );
				Db::update( 'debt_items', array( 'review_reason' => null ), array( 'id' => $item_id ) );
				self::recompute( $item_id );
				Audit::log( 'debt_item.review_cleared', 'debt_item', $item_id, null, null, $note );
			}
		);
	}

	/**
	 * Case-level money view (§2): due balance, future balance and what was paid —
	 * shown separately, never "the whole agreement is overdue".
	 */
	public static function case_summary( int $case_id ): array {
		$items = Db::rows( 'SELECT * FROM ' . Db::t( 'debt_items' ) . ' WHERE case_id = %d ORDER BY due_at, id', $case_id );
		$out   = array(
			'currency'          => null,
			'due_balance_minor' => 0,
			'not_due_minor'     => 0,
			'allocated_minor'   => 0,
			'draft_minor'       => 0,
			'review_items'      => 0,
			'items'             => array(),
			'signature'         => '',
			'mixed_currency'    => false,
		);
		$sig = array();
		foreach ( $items as $it ) {
			$alloc = (int) Db::value( 'SELECT COALESCE(SUM(amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' WHERE debt_item_id = %d', (int) $it['id'] );
			if ( null === $out['currency'] ) {
				$out['currency'] = $it['currency'];
			} elseif ( $out['currency'] !== $it['currency'] ) {
				$out['mixed_currency'] = true;
			}
			$bal = (int) $it['cached_balance_minor'];
			switch ( $it['finance_state'] ) {
				case 'open':
				case 'partially_paid':
					$out['due_balance_minor'] += $bal;
					break;
				case 'review':
					++$out['review_items'];
					break;
				case 'not_due':
					$out['not_due_minor'] += $bal;
					break;
				case 'draft':
					$out['draft_minor'] += (int) $it['original_amount_minor'];
					break;
			}
			$out['allocated_minor'] += $alloc;
			$sig[]                   = $it['id'] . ':' . $it['balance_version'];
			$it['allocated_minor']   = $alloc;
			$out['items'][]          = $it;
		}
		$out['signature'] = implode( ',', $sig );
		return $out;
	}

	/** Daily: items whose due date arrived move not_due -> open. */
	public static function refresh_due_states(): int {
		$ids = Db::rows( 'SELECT id FROM ' . Db::t( 'debt_items' ) . " WHERE finance_state = 'not_due' AND due_at <= %s", Clock::today() );
		foreach ( $ids as $r ) {
			Db::transaction( fn() => self::recompute( (int) $r['id'] ) );
		}
		return count( $ids );
	}

	/** Periodic check: cached balance equals the movement sum. Mismatches become exceptions, not silent fixes. */
	public static function verify_cache(): array {
		$rows = Db::rows(
			'SELECT d.id, d.cached_balance_minor, d.original_amount_minor, d.approved_at,
				(SELECT COALESCE(SUM(a.amount_minor),0) FROM ' . Db::t( 'adjustments' ) . ' a WHERE a.debt_item_id = d.id) AS adj,
				(SELECT COALESCE(SUM(l.amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' l WHERE l.debt_item_id = d.id) AS alloc
			FROM ' . Db::t( 'debt_items' ) . ' d'
		);
		$bad = array();
		foreach ( $rows as $r ) {
			$expected = null === $r['approved_at'] ? 0 : (int) $r['original_amount_minor'] + (int) $r['adj'] - (int) $r['alloc'];
			if ( $expected !== (int) $r['cached_balance_minor'] ) {
				$bad[] = (int) $r['id'];
				Exceptions::open( 'balance_mismatch:' . $r['id'], 'balance_mismatch', 'high', 'יתרה שמורה שונה מסכום התנועות', array( 'entity_type' => 'debt_item', 'entity_id' => (int) $r['id'], 'details' => array( 'cached' => (int) $r['cached_balance_minor'], 'expected' => $expected ) ) );
			}
		}
		return $bad;
	}
}
