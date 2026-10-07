<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Inbox;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Manual resolutions from the exception queue. Each one stores the mapping and
 * the evidence (§12: "פתרון החריג שומר את המיפוי והאסמכתה").
 */
final class Matching {

	/** Map a Tranzila standing order to a customer, then replay the events that waited for it. */
	public static function map_sto( array $d ): int {
		$customer = Customers::get( (int) ( $d['customer_id'] ?? 0 ) );
		$terminal = trim( (string) ( $d['terminal'] ?? '' ) );
		$sto      = trim( (string) ( $d['sto_id'] ?? '' ) );
		if ( ! $customer || '' === $terminal || '' === $sto ) {
			throw new DomainError( 'validation_failed', 'לקוח, מסוף ומזהה הוראה חובה', 400, array( 'sto_id' => 'חובה' ) );
		}
		$id = Db::insert(
			'recurring_orders',
			array(
				'customer_id'         => (int) $customer['id'],
				'agreement_id'        => ! empty( $d['agreement_id'] ) ? (int) $d['agreement_id'] : null,
				'terminal'            => $terminal,
				'sto_id'              => $sto,
				'status'              => 'active',
				'next_due_at'         => ! empty( $d['next_due_at'] ) ? substr( $d['next_due_at'], 0, 10 ) : null,
				'charge_dom'          => ! empty( $d['charge_dom'] ) ? (int) $d['charge_dom'] : null,
				'first_charge_date'   => ! empty( $d['first_charge_date'] ) ? substr( $d['first_charge_date'], 0, 10 ) : null,
				'charge_amount_minor' => isset( $d['charge_amount'] ) ? Money::parse( $d['charge_amount'] ) : null,
				'future_installments' => isset( $d['future_installments'] ) && '' !== $d['future_installments'] ? (int) $d['future_installments'] : null,
				'currency'            => 'ILS',
				'card_status'         => 'unknown',
				'created_at'          => Clock::utc(),
				'updated_at'          => Clock::utc(),
			)
		);
		Customers::map_identity( (int) $customer['id'], 'tranzila_sto', $terminal, $sto, true );
		Audit::log( 'sto.map', 'recurring_order', $id, null, array( 'terminal' => $terminal, 'sto' => $sto, 'customer' => (int) $customer['id'] ), (string) ( $d['evidence_ref'] ?? '' ) );
		// Replay events that waited for this mapping.
		$waiting = Db::rows( 'SELECT id, payload_redacted FROM ' . Db::t( 'inbox_events' ) . " WHERE provider = 'tranzila' AND processing_state = 'needs_match'" );
		foreach ( $waiting as $w ) {
			$p = (array) json_decode( (string) $w['payload_redacted'], true );
			if ( (string) ( $p['supplier'] ?? '' ) === $terminal && (string) ( $p['sto_external_id'] ?? '' ) === $sto ) {
				Inbox::replay( (int) $w['id'] );
			}
		}
		return $id;
	}

	/**
	 * A failed charge without a reliable cycle: the officer decides which payment it
	 * is (existing item, or a new item for a stated due date). That decision is the
	 * approval of the item.
	 */
	public static function attempt_to_item( int $attempt_id, array $d ): int {
		if ( ! current_user_can( 'icol_approve_debt' ) ) {
			throw new DomainError( 'forbidden', 'שיוך ניסיון חיוב מותר לאחראי גבייה או מנהל', 403 );
		}
		$a = Db::row( 'SELECT * FROM ' . Db::t( 'charge_attempts' ) . ' WHERE id = %d', $attempt_id );
		if ( ! $a ) {
			throw new DomainError( 'not_found', 'ניסיון החיוב לא נמצא', 404 );
		}
		if ( $a['debt_item_id'] ) {
			throw new DomainError( 'already_matched', 'הניסיון כבר משויך לפריט', 409 );
		}
		$order = Db::row( 'SELECT * FROM ' . Db::t( 'recurring_orders' ) . ' WHERE id = %d', (int) $a['recurring_order_id'] );
		return Db::transaction(
			function () use ( $a, $order, $d, $attempt_id ) {
				if ( ! empty( $d['debt_item_id'] ) ) {
					$item_id = (int) $d['debt_item_id'];
				} else {
					$due = (string) ( $d['due_at'] ?? '' );
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) {
						throw new DomainError( 'validation_failed', 'יש לבחור את מועד התשלום המקורי', 400, array( 'due_at' => 'חובה' ) );
					}
					$key  = 'tz:' . $order['terminal'] . ':' . $order['sto_id'] . ':m:' . substr( $due, 0, 7 );
					$item = Db::row( 'SELECT id FROM ' . Db::t( 'debt_items' ) . ' WHERE source_key = %s', $key );
					if ( $item ) {
						$item_id = (int) $item['id'];
					} else {
						$case = Db::row( 'SELECT * FROM ' . Db::t( 'cases' ) . ' WHERE active_key = %s', 'sto:' . $order['id'] );
						if ( ! $case ) {
							$cid  = Db::insert( 'cases', array( 'customer_id' => (int) $order['customer_id'], 'agreement_id' => $order['agreement_id'], 'recurring_order_id' => (int) $order['id'], 'case_key' => 'sto:' . $order['id'], 'active_key' => 'sto:' . $order['id'], 'source_type' => 'recurring_failure', 'entry_mode' => 'new', 'workflow_state' => 'draft', 'currency' => $a['currency'] ?: 'ILS', 'created_at' => Clock::utc(), 'updated_at' => Clock::utc() ) );
							$case = Workflow::get( $cid );
						}
						$item_id = Cases::insert_item( (int) $case['id'], array( 'amount_minor' => (int) $a['amount_minor'], 'due_at' => $due, 'description' => (string) ( $d['description'] ?? ( 'תשלום ' . gmdate( 'm/Y', strtotime( $due ) ) ) ), 'evidence_ref' => 'tranzila:' . $a['terminal'] . ':' . $a['transaction_index'] ), $a['currency'] ?: 'ILS', false, 'שיוך ידני של ניסיון חיוב שנכשל: ' . (string) ( $d['note'] ?? '' ), $key, 'my_billing' );
						Db::update( 'debt_items', array( 'approved_at' => Clock::utc(), 'approved_by' => get_current_user_id() ), array( 'id' => $item_id ) );
						Ledger::recompute( $item_id );
						if ( 'draft' === $case['workflow_state'] && ! empty( $d['activate'] ) ) {
							Workflow::transition( (int) $case['id'], 'active', 'הופעל לאחר שיוך ידני', null, array( 'activated_at' => Clock::utc(), 'activated_by' => get_current_user_id(), 'sequence_step' => 0, 'sequence_started_at' => Clock::utc() ), 'match' );
							Messaging::plan_next( (int) $case['id'] );
						}
					}
				}
				Db::update( 'charge_attempts', array( 'debt_item_id' => $item_id ), array( 'id' => $attempt_id ) );
				Exceptions::resolve_by_key( 'no_cycle:' . $a['terminal'] . ':' . $a['transaction_index'], 'שויך לפריט #' . $item_id . ( ! empty( $d['note'] ) ? ' · ' . $d['note'] : '' ) );
				Audit::log( 'attempt.match', 'charge_attempt', $attempt_id, null, array( 'item' => $item_id ), (string) ( $d['note'] ?? '' ) );
				return $item_id;
			}
		);
	}

	/** Allocate an unmatched verified payment by explicit decision (never "oldest first"). */
	public static function allocate_payment( int $payment_id, int $item_id, int $amount_minor, string $note ): int {
		if ( ! current_user_can( 'icol_verify_payment' ) ) {
			throw new DomainError( 'forbidden', 'שיוך תקבול מותר לאחראי גבייה או מנהל', 403 );
		}
		if ( '' === trim( $note ) ) {
			throw new DomainError( 'validation_failed', 'יש לתעד את הבסיס לשיוך', 400, array( 'note' => 'חובה' ) );
		}
		$id = Ledger::allocate( $payment_id, $item_id, $amount_minor, 'שיוך ידני: ' . $note );
		Payments::after_payment( $payment_id );
		foreach ( array( 'payment_without_debt:' . $payment_id, 'overpayment:' . $payment_id ) as $k ) {
			if ( Ledger::payment_unallocated( $payment_id ) <= 0 ) {
				Exceptions::resolve_by_key( $k, 'שויך ידנית: ' . $note );
			}
		}
		return $id;
	}

	/** Start a draft from a revenue-dashboard candidate. Amount and basis still need a person. */
	public static function candidate_to_draft( int $candidate_id, array $d ): array {
		$pc = Db::row( 'SELECT * FROM ' . Db::t( 'program_candidates' ) . ' WHERE id = %d', $candidate_id );
		if ( ! $pc ) {
			throw new DomainError( 'not_found', 'המועמד לא נמצא', 404 );
		}
		$snap = (array) json_decode( (string) $pc['snapshot'], true );
		$cid  = (int) Db::value( 'SELECT id FROM ' . Db::t( 'customers' ) . ' WHERE wp_user_id = %d LIMIT 1', (int) $pc['wp_user_id'] );
		if ( ! $cid ) {
			$user = get_userdata( (int) $pc['wp_user_id'] );
			$cid  = Customers::create(
				array(
					'wp_user_id' => (int) $pc['wp_user_id'],
					'full_name'  => $user ? $user->display_name : ( $snap['name'] ?? 'תלמיד' ),
					'first_name' => $user ? (string) $user->first_name : '',
					'first_name_reliable' => $user && '' !== (string) $user->first_name,
					'email'      => $user ? $user->user_email : ( $snap['email'] ?? '' ),
					'phone'      => (string) ( $snap['phone'] ?? '' ),
				)
			);
		}
		$resp = Cases::create_draft(
			array(
				'customer_id'  => $cid,
				'source_type'  => 'non_open_charge',
				'entry_mode'   => 'new',
				'currency'     => 'ILS',
				'clarification_first' => ! empty( $d['clarification_first'] ),
				'owner_id'     => ! empty( $d['owner_id'] ) ? (int) $d['owner_id'] : null,
				'approval_basis' => (string) ( $d['approval_basis'] ?? '' ),
				'agreement'    => array(
					'program'               => $snap['program'] ?? 'תוכנית ליווי למתחילים',
					'reference'             => (string) ( $d['agreement_ref'] ?? $snap['agreement_ref'] ?? '' ),
					'document_ref'          => (string) ( $d['document_ref'] ?? '' ),
					'joined_at'             => $snap['joined_at'] ?? null,
					'account_open_deadline' => $snap['effective_deadline'] ?? $pc['deadline'],
					'extensions'            => ! empty( $snap['extension_until'] ) ? array( array( 'until' => $snap['extension_until'] ) ) : array(),
					'status_checked_at'     => Clock::today(),
				),
				'debt_items'   => array(
					array(
						'amount'      => (string) ( $d['amount'] ?? '' ),
						'due_at'      => (string) ( $d['due_at'] ?? Clock::today() ),
						'description' => 'תוכנית הליווי למתחילים',
					),
				),
			)
		);
		Db::update( 'program_candidates', array( 'status' => 'drafted', 'case_id' => (int) $resp['case_id'], 'decided_by' => get_current_user_id() ?: null, 'updated_at' => Clock::utc() ), array( 'id' => $candidate_id ) );
		return $resp;
	}

	public static function dismiss_candidate( int $candidate_id, string $note ): void {
		if ( '' === trim( $note ) ) {
			throw new DomainError( 'validation_failed', 'יש לציין סיבה', 400, array( 'note' => 'חובה' ) );
		}
		Db::update( 'program_candidates', array( 'status' => 'dismissed', 'note' => mb_substr( $note, 0, 500 ), 'decided_by' => get_current_user_id() ?: null, 'updated_at' => Clock::utc() ), array( 'id' => $candidate_id ) );
		Audit::log( 'candidate.dismiss', 'candidate', $candidate_id, null, null, $note );
	}
}
