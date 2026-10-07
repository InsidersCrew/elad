<?php
namespace Insiders\Collections\Integrations\Tranzila;

use Insiders\Collections\Domain\Cases;
use Insiders\Collections\Domain\CardTasks;
use Insiders\Collections\Domain\Exceptions;
use Insiders\Collections\Domain\Ledger;
use Insiders\Collections\Domain\Messaging;
use Insiders\Collections\Domain\PaymentRequests;
use Insiders\Collections\Domain\Payments;
use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Engine\Inbox;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * My Billing / Notify event processing (§4, §8, §16).
 *
 * A notification is unauthenticated (no signature documented, 2026-10), so it is
 * a TRIGGER for a verification read, not proof. Until the transaction is read
 * back from the Reports API (or the admin records that Tranzila confirmed an
 * inbound authentication method), nothing here changes a balance or messages anyone.
 */
final class Processor {

	public static function terminals(): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'tranzila_terminals' ) ) ) ) );
	}

	public static function process( array $ev ): string {
		$p        = Inbox::payload( $ev );
		$kind     = 'pr' === $ev['integration_id'] ? 'pr' : 'sto';
		$terminal = trim( (string) ( $p['supplier'] ?? $p['terminal_name'] ?? '' ) );
		$index    = trim( (string) ( $p['index'] ?? $p['transaction_index'] ?? '' ) );
		if ( ! in_array( $terminal, self::terminals(), true ) ) {
			// AT28: an event for an unknown terminal changes nothing.
			Exceptions::open( 'unknown_terminal:' . $ev['id'], 'unverified_event', 'medium', 'אירוע ממסוף לא מוכר: ' . mb_substr( $terminal, 0, 40 ), array( 'inbox_event_id' => (int) $ev['id'] ) );
			return 'ignored';
		}
		if ( '' === $index ) {
			Exceptions::open( 'no_index:' . $ev['id'], 'unverified_event', 'medium', 'אירוע ללא מזהה עסקה', array( 'inbox_event_id' => (int) $ev['id'] ) );
			return 'needs_verification';
		}
		$fact = self::facts( $p, $terminal, $index );
		$v    = self::verify( $fact );
		if ( 'mismatch' === $v['state'] ) {
			Exceptions::open( 'mismatch:' . $terminal . ':' . $index, 'unverified_event', 'high', 'פרטי ההודעה אינם תואמים לדוח העסקאות (' . $v['reason'] . ')', array( 'inbox_event_id' => (int) $ev['id'] ) );
			return 'needs_verification';
		}
		if ( 'verified' !== $v['state'] ) {
			Exceptions::open( 'unverified:' . Clock::today(), 'unverified_event', 'medium', 'יש אירועי טרנזילה שממתינים לאימות מול דוח העסקאות', array( 'details' => array( 'last_event' => (int) $ev['id'], 'reason' => $v['reason'] ) ) );
			return 'needs_verification';
		}
		$fact = array_merge( $fact, $v['override'] ?? array() );

		if ( 'verification' === $fact['kind'] ) {
			return 'ignored'; // AT21: J-type card checks are not receipts.
		}
		if ( 'credit' === $fact['kind'] ) {
			Exceptions::open( 'credit:' . $terminal . ':' . $index, 'refund_or_chargeback', 'high', 'התקבלה עסקת זיכוי/החזר בסך ' . Money::format( (int) $fact['amount_minor'] ) . ' ₪, יש לקשר לתקבול המקורי', array( 'inbox_event_id' => (int) $ev['id'] ) );
			return 'needs_match';
		}
		if ( 'pr' === $kind ) {
			return self::payment_request_event( $ev, $fact, $p );
		}
		return self::sto_event( $ev, $fact, $p );
	}

	/** Normalized facts from the notification vocabulary. */
	public static function facts( array $p, string $terminal, string $index ): array {
		$tranmode = strtoupper( trim( (string) ( $p['tranmode'] ?? '' ) ) );
		$prefixes = array_filter( array_map( 'trim', explode( ',', strtoupper( (string) Settings::get( 'tranzila_charge_tranmodes' ) ) ) ) );
		$kind     = 'unknown';
		if ( '' === $tranmode ) {
			$kind = 'charge'; // My Billing STO charges; verified against the report anyway
		} elseif ( str_starts_with( $tranmode, 'C' ) ) {
			$kind = 'credit';
		} elseif ( str_starts_with( $tranmode, 'J' ) || str_starts_with( $tranmode, 'V' ) ) {
			$kind = 'verification';
		} else {
			foreach ( $prefixes as $pre ) {
				if ( str_starts_with( $tranmode, $pre ) ) {
					$kind = 'charge';
				}
			}
		}
		$token = (string) ( $p['TranzilaTK'] ?? '' );
		return array(
			'terminal'     => $terminal,
			'index'        => $index,
			'sto_id'       => trim( (string) ( $p['sto_external_id'] ?? $p['sto_id'] ?? '' ) ),
			'amount_minor' => Money::from_provider( $p['sum'] ?? '' ),
			'currency'     => Money::normalize_currency( $p['currency'] ?? '' ),
			'response'     => ResponseCodes::normalize( $p['Response'] ?? '' ),
			'tranmode'     => $tranmode,
			'kind'         => $kind,
			'token'        => $token,
			'expdate'      => (string) ( $p['expdate'] ?? '' ),
			'last4'        => strlen( $token ) >= 4 ? substr( $token, -4 ) : null,
			'method'       => '' !== $token ? 'card' : 'unknown',
			'occurred_ts'  => Clock::now(),
		);
	}

	/** Read the transaction back from Tranzila and compare the fields that matter. */
	public static function verify( array $f ): array {
		if ( ! Client::configured() ) {
			if ( Settings::on( 'tranzila_inbound_verified' ) ) {
				return array( 'state' => 'verified', 'reason' => 'trusted_inbound' );
			}
			return array( 'state' => 'unavailable', 'reason' => 'אין חיבור API לאימות' );
		}
		$r = Client::transaction( $f['terminal'], $f['index'] );
		if ( ! $r['ok'] ) {
			return array( 'state' => 'unavailable', 'reason' => (string) $r['error'] );
		}
		$row = $r['row'];
		$diff = array();
		if ( null !== $f['amount_minor'] && null !== $row['amount_minor'] && (int) $f['amount_minor'] !== (int) $row['amount_minor'] ) {
			$diff[] = 'סכום';
		}
		if ( '' !== $f['response'] && '' !== $row['response'] && $f['response'] !== $row['response'] ) {
			$diff[] = 'קוד תשובה';
		}
		if ( $f['currency'] && $row['currency'] && $f['currency'] !== $row['currency'] ) {
			$diff[] = 'מטבע';
		}
		if ( $diff ) {
			return array( 'state' => 'mismatch', 'reason' => implode( ', ', $diff ) );
		}
		$override = array_filter(
			array(
				'amount_minor' => $row['amount_minor'],
				'currency'     => $row['currency'],
				'response'     => $row['response'],
			),
			static fn( $v ) => null !== $v && '' !== $v
		);
		if ( '' !== $row['date'] ) {
			$ts = strtotime( $row['date'] . ' ' . Clock::tz()->getName() );
			if ( $ts ) {
				$override['occurred_ts'] = $ts;
			}
		}
		return array( 'state' => 'verified', 'reason' => 'report', 'override' => $override );
	}

	private static function sto_event( array $ev, array $f, array $p ): string {
		$order = Db::row( 'SELECT * FROM ' . Db::t( 'recurring_orders' ) . ' WHERE terminal = %s AND sto_id = %s', $f['terminal'], $f['sto_id'] );
		if ( ! $order ) {
			Exceptions::open( 'no_order:' . $f['terminal'] . ':' . $f['index'], 'event_without_customer', 'medium', 'אירוע להוראה ' . ( $f['sto_id'] ?: '(ללא מזהה)' ) . ' שאינה ממופה ללקוח', array( 'inbox_event_id' => (int) $ev['id'], 'details' => array( 'terminal' => $f['terminal'], 'sto_id' => $f['sto_id'], 'index' => $f['index'] ) ) );
			return 'needs_match';
		}
		$success = ResponseCodes::is_success( $f['response'] );
		$cycle   = Cycle::resolve( $order, $p, (int) $f['occurred_ts'], ! $success );
		return $success ? self::sto_success( $ev, $f, $order, $cycle ) : self::sto_failure( $ev, $f, $order, $cycle );
	}

	private static function sto_failure( array $ev, array $f, array $order, ?string $cycle ): string {
		$class = ResponseCodes::classify( $f['response'] );
		return Db::transaction(
			function () use ( $ev, $f, $order, $cycle, $class ) {
				$attempt = self::attempt( $ev, $f, $order, null, $class, $cycle );
				if ( null === $attempt ) {
					return 'processed'; // same attempt seen before (AT02)
				}
				if ( in_array( $class, array( 'expired_card', 'card_invalid', 'suspected_fraud' ), true ) ) {
					Db::update( 'recurring_orders', array( 'card_status' => 'update_required', 'card_status_basis' => 'failure:' . $f['response'], 'updated_at' => Clock::utc() ), array( 'id' => (int) $order['id'] ) );
				}
				if ( null === $cycle ) {
					// AT05: no reliable cycle -> manual match, no outreach.
					Exceptions::open( 'no_cycle:' . $f['terminal'] . ':' . $f['index'], 'charge_without_cycle', 'medium', 'חיוב שנכשל (' . ResponseCodes::LABELS[ $class ] . ', ' . Money::format( (int) $f['amount_minor'] ) . ' ₪) ללא מחזור מזוהה', array( 'entity_type' => 'charge_attempt', 'entity_id' => $attempt, 'customer_id' => (int) $order['customer_id'], 'inbox_event_id' => (int) $ev['id'] ) );
					return 'needs_match';
				}
				$key  = Cycle::source_key( $f['terminal'], $f['sto_id'], $cycle );
				$item = Db::row( 'SELECT * FROM ' . Db::t( 'debt_items' ) . ' WHERE source_key = %s FOR UPDATE', $key );
				if ( $item ) {
					// AT03: another attempt for the same cycle joins the same item. AT06: settled stays settled.
					Db::update( 'charge_attempts', array( 'debt_item_id' => (int) $item['id'] ), array( 'id' => $attempt ) );
					return 'processed';
				}
				self::open_item( $order, $f, $key, $cycle, $attempt );
				return 'processed';
			}
		);
	}

	/** Creates the debt item (system-approved: a verified failed charge) and activates or updates the case. */
	private static function open_item( array $order, array $f, string $key, string $cycle, int $attempt_id ): int {
		$case = Db::row( 'SELECT * FROM ' . Db::t( 'cases' ) . ' WHERE active_key = %s FOR UPDATE', 'sto:' . $order['id'] );
		if ( ! $case ) {
			$case_id = Db::insert(
				'cases',
				array(
					'customer_id'        => (int) $order['customer_id'],
					'agreement_id'       => $order['agreement_id'],
					'recurring_order_id' => (int) $order['id'],
					'case_key'           => 'sto:' . $order['id'],
					'active_key'         => 'sto:' . $order['id'],
					'source_type'        => 'recurring_failure',
					'entry_mode'         => 'new',
					'workflow_state'     => 'draft',
					'currency'           => $f['currency'] ?: 'ILS',
					'owner_id'           => (int) Settings::get( 'fallback_owner_id', 0 ) ?: null,
					'created_at'         => Clock::utc(),
					'updated_at'         => Clock::utc(),
				)
			);
			$case = Db::row( 'SELECT * FROM ' . Db::t( 'cases' ) . ' WHERE id = %d', $case_id );
		}
		$due = Clock::local_date( (int) $f['occurred_ts'] );
		if ( str_starts_with( $cycle, 's:' ) ) {
			$due = Cycle::scheduled_date( (int) substr( $cycle, 2, 4 ), (int) substr( $cycle, 7, 2 ), (int) $order['charge_dom'] );
		}
		$item_id = Cases::insert_item(
			(int) $case['id'],
			array(
				'amount_minor' => (int) $f['amount_minor'],
				'due_at'       => $due,
				'description'  => 'תשלום חודשי ' . gmdate( 'm/Y', strtotime( $due ) ),
				'evidence_ref' => 'tranzila:' . $f['terminal'] . ':' . $f['index'],
			),
			$f['currency'] ?: 'ILS',
			false,
			'חיוב חוזר שנכשל ואומת מול טרנזילה (קוד ' . $f['response'] . ')',
			$key,
			'my_billing'
		);
		Db::update( 'debt_items', array( 'approved_at' => Clock::utc(), 'approved_by' => null ), array( 'id' => $item_id ) );
		Ledger::recompute( $item_id );
		// Link the attempt before planning: the first-message timing reads its failure class.
		Db::update( 'charge_attempts', array( 'debt_item_id' => $item_id ), array( 'id' => $attempt_id ) );
		Audit::log( 'debt_item.from_tranzila', 'debt_item', $item_id, null, array( 'source_key' => $key, 'amount' => (int) $f['amount_minor'] ), 'verified failed charge' );

		if ( 'draft' === $case['workflow_state'] ) {
			Workflow::transition( (int) $case['id'], 'active', 'כשל חיוב מאומת', null, array( 'activated_at' => Clock::utc(), 'sequence_step' => 0, 'sequence_started_at' => Clock::utc() ), 'tranzila' );
			Messaging::plan_next( (int) $case['id'] );
		} elseif ( in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			$has_pending = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'scheduled_actions' ) . " WHERE case_id = %d AND state = 'pending'", (int) $case['id'] );
			if ( ! $has_pending ) {
				Messaging::plan_next( (int) $case['id'] );
			}
		} else {
			// Case is with a person (review, promise, paused): add the item, tell the owner, send nothing.
			Tasks::open( 'new_item:' . $item_id, 'reply_review', array( 'case_id' => (int) $case['id'], 'reason' => 'נוסף פריט חוב חדש לתיק שנמצא במצב ' . Workflow::label( $case['workflow_state'] ) ) );
		}
		return $item_id;
	}

	/** Records the attempt once per (terminal, index). Returns null when it already existed. */
	private static function attempt( array $ev, array $f, array $order, ?int $item_id, string $class, ?string $cycle ): ?int {
		$exists = Db::value( 'SELECT id FROM ' . Db::t( 'charge_attempts' ) . ' WHERE terminal = %s AND transaction_index = %s', $f['terminal'], $f['index'] );
		if ( $exists ) {
			return null;
		}
		return Db::insert(
			'charge_attempts',
			array(
				'debt_item_id'       => $item_id,
				'recurring_order_id' => (int) $order['id'],
				'terminal'           => $f['terminal'],
				'transaction_index'  => $f['index'],
				'response_code'      => $f['response'],
				'failure_class'      => ResponseCodes::is_success( $f['response'] ) ? '' : $class,
				'amount_minor'       => $f['amount_minor'],
				'currency'           => $f['currency'],
				'cycle_ref'          => $cycle,
				'occurred_at'        => Clock::utc( (int) $f['occurred_ts'] ),
				'inbox_event_id'     => (int) $ev['id'],
				'verification_state' => 'verified',
				'created_at'         => Clock::utc(),
			)
		);
	}

	private static function sto_success( array $ev, array $f, array $order, ?string $cycle ): string {
		if ( (int) $f['amount_minor'] <= 0 ) {
			return 'ignored';
		}
		[ $pid, $created ] = Payments::record(
			array(
				'provider'            => 'tranzila',
				'terminal'            => $f['terminal'],
				'transaction_id'      => $f['index'],
				'customer_id'         => (int) $order['customer_id'],
				'recurring_order_id'  => (int) $order['id'],
				'amount_minor'        => (int) $f['amount_minor'],
				'currency'            => $f['currency'] ?: 'ILS',
				'origin'              => 'sto',
				'payment_method'      => 'card',
				'has_reusable_token'  => '' !== $f['token'] ? 1 : 0,
				'card_last4'          => $f['last4'],
				'cycle_ref'           => $cycle,
				'occurred_at'         => Clock::utc( (int) $f['occurred_ts'] ),
				'verification_source' => 'report',
			)
		);
		if ( ! $created ) {
			return 'processed'; // AT20: same transaction from webhook and report
		}
		self::attempt( $ev, $f, $order, null, '', $cycle );
		// The standing order itself charged successfully: its card works.
		Db::update( 'recurring_orders', array( 'card_status' => 'verified', 'card_status_basis' => 'sto_charge_succeeded:' . $f['index'], 'updated_at' => Clock::utc() ), array( 'id' => (int) $order['id'] ) );

		$open_items = Db::rows(
			'SELECT d.* FROM ' . Db::t( 'debt_items' ) . ' d JOIN ' . Db::t( 'cases' ) . " c ON c.id = d.case_id WHERE c.recurring_order_id = %d AND d.finance_state IN ('open','partially_paid','not_due')",
			(int) $order['id']
		);
		if ( null !== $cycle ) {
			$key  = Cycle::source_key( $f['terminal'], $f['sto_id'], $cycle );
			$item = Db::row( 'SELECT * FROM ' . Db::t( 'debt_items' ) . ' WHERE source_key = %s', $key );
			if ( $item && in_array( $item['finance_state'], array( 'open', 'partially_paid', 'not_due' ), true ) ) {
				$amt = min( (int) $f['amount_minor'], (int) $item['cached_balance_minor'] );
				Ledger::allocate( $pid, (int) $item['id'], $amt, 'חיוב חוזר הצליח למחזור ' . $cycle );
				Db::update( 'charge_attempts', array( 'debt_item_id' => (int) $item['id'] ), array( 'terminal' => $f['terminal'], 'transaction_index' => $f['index'] ) );
				Payments::after_payment( $pid );
				return 'processed';
			}
		}
		if ( $open_items ) {
			// AT44: a success with open items but no proven cycle is not allocated to "the oldest".
			Exceptions::open( 'unmatched_success:' . $f['terminal'] . ':' . $f['index'], 'payment_without_debt', 'high', 'חיוב חוזר הצליח (' . Money::format( (int) $f['amount_minor'] ) . ' ₪) ויש פריטים פתוחים בהוראה, נדרש שיוך ידני', array( 'entity_type' => 'payment', 'entity_id' => $pid, 'customer_id' => (int) $order['customer_id'] ) );
			return 'needs_match';
		}
		Payments::after_payment( $pid, false ); // routine installment, nothing owed
		return 'processed';
	}

	/** Notify from a payment request we created. Matched only by a confirmed identifier, then verified. */
	private static function payment_request_event( array $ev, array $f, array $p ): string {
		$field = (string) Settings::get( 'tranzila_pr_match_field' );
		$pr_id = '' !== $field ? trim( (string) ( $p[ $field ] ?? '' ) ) : trim( (string) ( $p['pr_id'] ?? '' ) );
		$req   = '' !== $pr_id ? Db::row( 'SELECT * FROM ' . Db::t( 'payment_requests' ) . ' WHERE provider_pr_id = %s', $pr_id ) : null;
		if ( ! $req || ! ResponseCodes::is_success( $f['response'] ) ) {
			if ( ResponseCodes::is_success( $f['response'] ) ) {
				Exceptions::open( 'pr_unmatched:' . $f['terminal'] . ':' . $f['index'], 'payment_without_debt', 'high', 'תשלום דרך בקשת תשלום שלא הותאם לבקשה במערכת', array( 'inbox_event_id' => (int) $ev['id'] ) );
				return 'needs_match';
			}
			return 'processed';
		}
		$case = Workflow::get( (int) $req['case_id'] );
		if ( $f['currency'] && $f['currency'] !== $req['currency'] ) {
			Exceptions::open( 'pr_currency:' . $req['id'], 'payment_without_debt', 'high', 'מטבע התשלום שונה ממטבע הבקשה', array( 'entity_type' => 'payment_request', 'entity_id' => (int) $req['id'] ) );
			return 'needs_match';
		}
		[ $pid, $created ] = Payments::record(
			array(
				'provider'            => 'tranzila',
				'terminal'            => $f['terminal'],
				'transaction_id'      => $f['index'],
				'customer_id'         => (int) $case['customer_id'],
				'payment_request_id'  => (int) $req['id'],
				'amount_minor'        => (int) $f['amount_minor'],
				'currency'            => $f['currency'] ?: $req['currency'],
				'origin'              => 'payment_request',
				'payment_method'      => $f['method'],
				'has_reusable_token'  => '' !== $f['token'] ? 1 : 0,
				'card_last4'          => $f['last4'],
				'occurred_at'         => Clock::utc( (int) $f['occurred_ts'] ),
				'verification_source' => 'report',
			)
		);
		if ( ! $created ) {
			return 'processed';
		}
		if ( '' !== $f['token'] ) {
			CardTasks::store_credential( $pid, $f['terminal'], $f['token'], $f['expdate'] );
		}
		$left  = (int) $f['amount_minor'];
		$items = Db::rows( 'SELECT * FROM ' . Db::t( 'request_items' ) . ' WHERE payment_request_id = %d', (int) $req['id'] );
		foreach ( $items as $ri ) {
			$item = Ledger::item( (int) $ri['debt_item_id'] );
			$amt  = min( $left, (int) $ri['requested_amount_minor'], (int) $item['cached_balance_minor'] );
			if ( $amt > 0 ) {
				Ledger::allocate( $pid, (int) $ri['debt_item_id'], $amt, 'תשלום דרך בקשת תשלום #' . $req['id'] );
				$left -= $amt;
			}
		}
		PaymentRequests::mark_paid( (int) $req['id'] );
		Payments::after_payment( $pid ); // leftover -> overpayment exception (AT19)
		return 'processed';
	}
}
