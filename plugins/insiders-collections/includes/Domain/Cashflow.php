<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Integrations\Tranzila\Client as Tranzila;
use Insiders\Collections\Integrations\Tranzila\Processor;
use Insiders\Collections\Integrations\Tranzila\ResponseCodes;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Expected cash for the finance dashboard (IFD), read-only.
 *
 * Three sources, kept apart so the reader decides how much to trust each:
 *  - the standing-order book: every active My Billing STO (stos/get), amount and
 *    schedule only, no name, token or card number;
 *  - what this plugin is collecting: card charges already scheduled, approved
 *    promises, and open approved balances with no date yet;
 *  - monthly actuals per terminal from the transaction report. The sales terminal
 *    (the "mini store") has no future in Tranzila, only this history.
 *
 * IFD reads it through the ifd_cashflow_forecast filter (docs/cashflow-contract.md),
 * never through this class, so an internal change here cannot break it silently.
 */
final class Cashflow {
	public const CONTRACT   = 1;
	private const SYNC      = 'icol_cashflow_sync';
	private const HISTORY   = 'icol_terminal_history';
	private const STALE     = 36 * HOUR_IN_SECONDS;
	private const BACK      = 6;  // complete months of history kept per terminal
	private const PER_RUN   = 3;  // months fetched per terminal in one run, so a first backfill stays short
	private const MAX_PAGES = 40;

	/* ------------------------------------------------------------------ sync */

	/** The scheduled job: the STO book, then the history. Throws when either failed, after both ran. */
	public static function sync(): array {
		$errors = array();
		$out    = array();
		foreach ( array( 'stos' => array( self::class, 'sync_stos' ), 'history' => array( self::class, 'sync_history' ) ) as $k => $fn ) {
			try {
				$out[ $k ] = $fn();
			} catch ( \Throwable $e ) {
				$errors[] = $k . ': ' . $e->getMessage();
			}
		}
		if ( $errors ) {
			throw new \RuntimeException( implode( ' | ', $errors ) );
		}
		return $out;
	}

	public static function sync_stos(): array {
		$terminal = (string) Settings::get( 'tranzila_my_billing_terminal' );
		$state    = self::state();
		$state['stos']['attempt_at'] = Clock::utc();
		update_option( self::SYNC, $state, false );
		$r = Tranzila::stos( $terminal, 'active' );
		if ( ! $r['ok'] ) {
			self::fail( 'stos', $r['error'] );
		}
		$active = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'sto_book' ) . " WHERE terminal = %s AND status = 'active'", $terminal );
		if ( ! $r['stos'] && $active > 10 ) {
			// An empty answer for a book of this size is a provider fault, not a mass cancellation.
			self::fail( 'stos', 'טרנזילה החזירה רשימה ריקה, מתוך ' . $active . ' הוראות פעילות. הספר לא עודכן.' );
		}
		$now  = Clock::utc();
		$seen = array();
		foreach ( $r['stos'] as $s ) {
			$s  = (array) $s;
			$id = trim( (string) ( $s['sto_id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$card = (array) ( $s['card'] ?? array() );
			$exp  = ( (int) ( $card['expire_month'] ?? 0 ) && (int) ( $card['expire_year'] ?? 0 ) ) ? sprintf( '%04d-%02d', self::year( (int) $card['expire_year'] ), (int) $card['expire_month'] ) : null;
			$row  = array(
				'status'              => mb_substr( (string) ( $s['sto_status'] ?? 'active' ), 0, 20 ),
				'charge_amount_minor' => Money::from_provider( $s['charge_amount'] ?? null ),
				'currency'            => Money::normalize_currency( $s['item']['price_currency'] ?? 'ILS' ) ?: 'ILS',
				'vat_percent'         => isset( $s['item']['vat_percent'] ) && is_numeric( $s['item']['vat_percent'] ) ? (float) $s['item']['vat_percent'] : null,
				'frequency'           => mb_substr( (string) ( $s['charge_frequency'] ?? '' ), 0, 20 ),
				'charge_dom'          => isset( $s['charge_dom'] ) && (int) $s['charge_dom'] ? (int) $s['charge_dom'] : null,
				'payments_number'     => isset( $s['sto_payments_number'] ) && is_numeric( $s['sto_payments_number'] ) ? (int) $s['sto_payments_number'] : null,
				'first_charge_date'   => self::date( $s['first_charge_date'] ?? null ),
				// The live API names them last_/next_charge_date; the docs say *_date_time.
				'next_charge_date'    => self::date( $s['next_charge_date'] ?? $s['next_charge_date_time'] ?? null ),
				'last_charge_date'    => self::date( $s['last_charge_date'] ?? $s['last_charge_date_time'] ?? null ),
				'card_expiry'         => $exp,
				'seen_at'             => $now,
			);
			// insert/update, not a prepared upsert: prepare() turns a NULL date into '', which strict MySQL refuses.
			if ( Db::value( 'SELECT sto_id FROM ' . Db::t( 'sto_book' ) . ' WHERE terminal = %s AND sto_id = %s', $terminal, $id ) ) {
				Db::update( 'sto_book', $row, array( 'terminal' => $terminal, 'sto_id' => $id ) );
			} else {
				Db::insert( 'sto_book', array( 'terminal' => $terminal, 'sto_id' => $id ) + $row );
			}
			$seen[] = $id;
		}
		// Not in today's list of active orders: cancelled, finished or paused at Tranzila.
		$gone = $seen
			? Db::exec( 'UPDATE ' . Db::t( 'sto_book' ) . " SET status = 'inactive' WHERE terminal = %s AND status = 'active' AND sto_id NOT IN (" . Db::in( $seen, '%s' ) . ')', $terminal, ...$seen )
			: Db::exec( 'UPDATE ' . Db::t( 'sto_book' ) . " SET status = 'inactive' WHERE terminal = %s AND status = 'active'", $terminal );
		$seen = count( $seen );
		$state = self::state();
		$state['stos'] = array( 'attempt_at' => $state['stos']['attempt_at'] ?? $now, 'ok_at' => $now, 'error' => null, 'count' => $seen );
		update_option( self::SYNC, $state, false );
		return array( 'active' => $seen, 'gone' => $gone );
	}

	/**
	 * Monthly approved totals per terminal: this month and last month every run (late
	 * refunds land there), older complete months once, until BACK months are kept.
	 */
	public static function sync_history(): array {
		$state = self::state();
		$state['history']['attempt_at'] = Clock::utc();
		update_option( self::SYNC, $state, false );
		$hist   = (array) get_option( self::HISTORY, array() );
		$cur    = substr( Clock::today(), 0, 7 );
		$months = array();
		for ( $i = 0; $i <= self::BACK; $i++ ) {
			$months[] = self::shift( $cur, -$i );
		}
		$done = 0;
		foreach ( Processor::terminals() as $terminal ) {
			$n = 0;
			foreach ( $months as $i => $m ) {
				$have = $hist[ $terminal ][ $m ] ?? null;
				if ( $i > 1 && $have && ! empty( $have['complete'] ) ) {
					continue;
				}
				if ( $n >= self::PER_RUN ) {
					break;
				}
				$hist[ $terminal ][ $m ] = self::month_totals( $terminal, $m, $m === $cur );
				++$n;
				++$done;
			}
			// Keep the window: older months drop off.
			$hist[ $terminal ] = array_intersect_key( (array) ( $hist[ $terminal ] ?? array() ), array_flip( $months ) );
			ksort( $hist[ $terminal ] );
		}
		update_option( self::HISTORY, $hist, false );
		$state = self::state();
		$state['history'] = array( 'attempt_at' => $state['history']['attempt_at'] ?? Clock::utc(), 'ok_at' => Clock::utc(), 'error' => null, 'months_fetched' => $done );
		update_option( self::SYNC, $state, false );
		return array( 'months_fetched' => $done );
	}

	private static function month_totals( string $terminal, string $month, bool $current ): array {
		$from  = $month . '-01';
		$to    = $current ? Clock::today() : gmdate( 'Y-m-t', strtotime( $from ) );
		$debit = 0;
		$credit = 0;
		$count = 0;
		$other = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$r = Tranzila::transactions( $terminal, array( 'transaction_start_date' => $from, 'transaction_end_date' => $to ), $page, 500 );
			if ( ! $r['ok'] ) {
				self::fail( 'history', $terminal . ' ' . $month . ': ' . $r['error'] );
			}
			foreach ( $r['rows'] as $row ) {
				$row = (array) $row;
				$n   = Tranzila::normalize_report_row( $row );
				if ( ! ResponseCodes::is_success( $n['response'] ) || null === $n['amount_minor'] ) {
					continue;
				}
				$type = strtoupper( (string) ( $row['txn_type'] ?? 'DEBIT' ) );
				if ( in_array( $type, array( 'DEBIT', 'FORCE' ), true ) ) {
					$debit += abs( (int) $n['amount_minor'] );
					++$count;
				} elseif ( 'CREDIT' === $type ) {
					$credit += abs( (int) $n['amount_minor'] );
					++$count;
				} else {
					$other[ $type ] = ( $other[ $type ] ?? 0 ) + 1; // a verification or a void moves no money we can count
				}
			}
			if ( count( $r['rows'] ) < 500 ) {
				break;
			}
		}
		return array(
			'debit_minor'  => $debit,
			'credit_minor' => $credit,
			'count'        => $count,
			'other_types'  => $other,
			'complete'     => ! $current && Clock::today() > gmdate( 'Y-m-d', strtotime( $to . ' +5 days' ) ),
			'fetched_at'   => Clock::utc(),
		);
	}

	/* -------------------------------------------------------------- forecast */

	/** add_filter( 'ifd_cashflow_forecast', ... ): null while there is nothing true to say. */
	public static function filter( $value, $args = array() ) {
		if ( null !== $value ) {
			return $value;
		}
		$args = is_array( $args ) ? $args : array();
		return self::forecast( (string) ( $args['from'] ?? '' ), (int) ( $args['months'] ?? 6 ) );
	}

	public static function forecast( string $from = '', int $months = 6 ): ?array {
		$state = self::state();
		if ( empty( $state['stos']['ok_at'] ) ) {
			return null; // never synced: IFD shows the source as missing, not as zero
		}
		$cur    = substr( Clock::today(), 0, 7 );
		$from   = preg_match( '/^\d{4}-\d{2}$/', $from ) ? $from : $cur;
		$months = max( 1, min( 24, $months ) );
		$range  = array();
		for ( $i = 0; $i < $months; $i++ ) {
			$range[] = self::shift( $from, $i );
		}
		$empty = static fn() => array( 'minor' => 0, 'count' => 0 );
		$out   = array();
		foreach ( $range as $m ) {
			$out[ $m ] = array( 'scheduled' => $empty(), 'at_risk' => $empty() + array( 'reasons' => array() ), 'collections' => $empty() + array( 'kinds' => array() ) );
		}
		$unsupported = 0;
		$failing     = self::failing_stos();
		$today       = Clock::today();
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'sto_book' ) . " WHERE status = 'active'" ) as $s ) {
			$charges = self::charges( $s, end( $range ) );
			if ( null === $charges ) {
				++$unsupported;
				continue;
			}
			foreach ( $charges as $date ) {
				$m = substr( $date, 0, 7 );
				if ( ! isset( $out[ $m ] ) || $date < $today ) {
					continue; // a charge before today is already in the actuals, or failed
				}
				$reason = null;
				if ( $s['card_expiry'] && $m > $s['card_expiry'] ) {
					$reason = 'card_expired';
				} elseif ( isset( $failing[ $s['terminal'] . ':' . $s['sto_id'] ] ) ) {
					$reason = 'recent_failure';
				} elseif ( $s['next_charge_date'] && $s['next_charge_date'] < gmdate( 'Y-m-d', strtotime( $today . ' -3 days' ) ) ) {
					$reason = 'overdue'; // Tranzila did not move the date on: the order is stuck
				}
				$bucket = $reason ? 'at_risk' : 'scheduled';
				$out[ $m ][ $bucket ]['minor'] += (int) $s['charge_amount_minor'];
				++$out[ $m ][ $bucket ]['count'];
				if ( $reason ) {
					$out[ $m ]['at_risk']['reasons'][ $reason ] = ( $out[ $m ]['at_risk']['reasons'][ $reason ] ?? 0 ) + 1;
				}
			}
		}
		[ $dated, $open ] = self::collections();
		foreach ( $dated as $c ) {
			$m = substr( $c['date'], 0, 7 );
			if ( isset( $out[ $m ] ) && $c['date'] >= $today ) {
				$out[ $m ]['collections']['minor'] += $c['minor'];
				++$out[ $m ]['collections']['count'];
				$out[ $m ]['collections']['kinds'][ $c['kind'] ] = ( $out[ $m ]['collections']['kinds'][ $c['kind'] ] ?? 0 ) + 1;
			}
		}
		$hist   = (array) get_option( self::HISTORY, array() );
		$result = array();
		foreach ( $out as $m => $o ) {
			$row = array(
				'month'       => $m,
				'partial'     => $m === $cur, // this month: only what is still ahead of today
				'scheduled'   => self::money( $o['scheduled'] ),
				'at_risk'     => self::money( $o['at_risk'] ) + array( 'reasons' => $o['at_risk']['reasons'] ),
				'collections' => self::money( $o['collections'] ) + array( 'kinds' => $o['collections']['kinds'] ),
			);
			if ( $m <= $cur ) {
				$row['actual'] = array();
				foreach ( $hist as $terminal => $by_month ) {
					$h = $by_month[ $m ] ?? null;
					$row['actual'][ $terminal ] = $h ? array(
						'gross'       => round( ( $h['debit_minor'] - $h['credit_minor'] ) / 100, 2 ),
						'debit'       => round( $h['debit_minor'] / 100, 2 ),
						'credit'      => round( $h['credit_minor'] / 100, 2 ),
						'count'       => (int) $h['count'],
						'complete'    => (bool) $h['complete'],
						'fetched_at'  => $h['fetched_at'],
						'other_types' => $h['other_types'],
					) : null;
				}
			}
			$result[] = $row;
		}
		$history = array();
		foreach ( $hist as $terminal => $by_month ) {
			foreach ( $by_month as $m => $h ) {
				$history[ $terminal ][ $m ] = array( 'gross' => round( ( $h['debit_minor'] - $h['credit_minor'] ) / 100, 2 ), 'count' => (int) $h['count'], 'complete' => (bool) $h['complete'] );
			}
		}
		$stale = static fn( ?string $at ) => ! $at || Clock::now() - (int) Clock::ts( $at ) > self::STALE;
		return array(
			'contract'         => self::CONTRACT,
			'computed_at'      => Clock::utc(),
			'currency'         => 'ILS',
			'amounts'          => 'gross', // as charged, VAT included
			'sources'          => array(
				'stos'    => array( 'ok_at' => $state['stos']['ok_at'], 'attempt_at' => $state['stos']['attempt_at'] ?? null, 'error' => $state['stos']['error'] ?? null, 'stale' => $stale( $state['stos']['ok_at'] ), 'count' => (int) ( $state['stos']['count'] ?? 0 ), 'terminal' => (string) Settings::get( 'tranzila_my_billing_terminal' ) ),
				'history' => array( 'ok_at' => $state['history']['ok_at'] ?? null, 'attempt_at' => $state['history']['attempt_at'] ?? null, 'error' => $state['history']['error'] ?? null, 'stale' => $stale( $state['history']['ok_at'] ?? null ) ),
			),
			'months'           => $result,
			'open_unscheduled' => self::money( $open ),
			'unsupported_stos' => $unsupported,
			'history'          => $history,
		);
	}

	/**
	 * Charge dates of one standing order up to $until (YYYY-MM), from Tranzila's own
	 * next date and the number of payments left. Null when the schedule is not monthly
	 * or has no date to start from: better counted as unknown than guessed.
	 */
	private static function charges( array $s, string $until ): ?array {
		if ( 'monthly' !== strtolower( (string) $s['frequency'] ) || null === $s['charge_amount_minor'] ) {
			return null;
		}
		$next = $s['next_charge_date'] ?: ( $s['last_charge_date'] ? gmdate( 'Y-m-d', strtotime( substr( $s['last_charge_date'], 0, 7 ) . '-01 +1 month' ) ) : $s['first_charge_date'] );
		if ( ! $next ) {
			return null;
		}
		$start = substr( $next, 0, 7 );
		$left  = PHP_INT_MAX;
		if ( (int) $s['payments_number'] > 0 && $s['first_charge_date'] ) {
			// Payments made so far = months from the first charge to the next one.
			$left = (int) $s['payments_number'] - max( 0, self::months_between( substr( $s['first_charge_date'], 0, 7 ), $start ) );
		}
		$dom   = (int) ( $s['charge_dom'] ?: (int) substr( $next, 8, 2 ) );
		$dates = array();
		for ( $m = $start, $i = 0; $m <= $until && $i < $left; $m = self::shift( $m, 1 ), $i++ ) {
			$dates[] = $m . '-' . sprintf( '%02d', min( $dom, (int) gmdate( 't', strtotime( $m . '-01' ) ) ) );
		}
		return $dates;
	}

	/** Orders whose last charge failed and is still being collected here. */
	private static function failing_stos(): array {
		$rows = Db::rows(
			'SELECT DISTINCT o.terminal, o.sto_id FROM ' . Db::t( 'debt_items' ) . ' d JOIN ' . Db::t( 'cases' ) . ' c ON c.id = d.case_id JOIN ' . Db::t( 'recurring_orders' ) . " o ON o.id = c.recurring_order_id
			 WHERE d.collection_owner = 'my_billing' AND d.finance_state IN ('open','partially_paid') AND c.workflow_state <> 'closed'"
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r['terminal'] . ':' . $r['sto_id'] ] = true;
		}
		return $out;
	}

	/**
	 * What this plugin expects to collect: a dated card charge, else an approved
	 * promise, else the open approved balance with no date (one figure, not spread).
	 *
	 * @return array{0: array<int, array{date:string,minor:int,kind:string}>, 1: array{minor:int,count:int}}
	 */
	private static function collections(): array {
		$dated = array();
		$cases = array();
		foreach ( Db::rows( 'SELECT case_id, run_at, payload FROM ' . Db::t( 'scheduled_actions' ) . " WHERE type = 'card_charge' AND state = 'pending'" ) as $a ) {
			$p    = (array) json_decode( (string) $a['payload'], true );
			$item = Ledger::item( (int) ( $p['item_id'] ?? 0 ) );
			if ( $item && (int) $item['cached_balance_minor'] > 0 ) {
				$dated[]                     = array( 'date' => Clock::local_date( (int) Clock::ts( $a['run_at'] ) ), 'minor' => (int) $item['cached_balance_minor'], 'kind' => 'card_charge' );
				$cases[ (int) $a['case_id'] ] = true;
			}
		}
		foreach ( Db::rows( 'SELECT p.case_id, p.promised_at, p.amount_minor FROM ' . Db::t( 'promises' ) . ' p JOIN ' . Db::t( 'cases' ) . " c ON c.id = p.case_id WHERE p.state = 'approved' AND c.workflow_state <> 'closed' ORDER BY p.promised_at" ) as $p ) {
			if ( isset( $cases[ (int) $p['case_id'] ] ) ) {
				continue;
			}
			$minor = null !== $p['amount_minor'] ? (int) $p['amount_minor'] : (int) Ledger::case_summary( (int) $p['case_id'] )['due_balance_minor'];
			if ( $minor > 0 ) {
				$dated[]                     = array( 'date' => $p['promised_at'], 'minor' => $minor, 'kind' => 'promise' );
				$cases[ (int) $p['case_id'] ] = true;
			}
		}
		$open = array( 'minor' => 0, 'count' => 0 );
		foreach ( Db::rows( 'SELECT d.case_id, SUM(d.cached_balance_minor) AS bal FROM ' . Db::t( 'debt_items' ) . ' d JOIN ' . Db::t( 'cases' ) . " c ON c.id = d.case_id WHERE d.approved_at IS NOT NULL AND d.finance_state IN ('open','partially_paid') AND c.workflow_state <> 'closed' GROUP BY d.case_id" ) as $r ) {
			if ( ! isset( $cases[ (int) $r['case_id'] ] ) && (int) $r['bal'] > 0 ) {
				$open['minor'] += (int) $r['bal'];
				++$open['count'];
			}
		}
		return array( $dated, $open );
	}

	/* --------------------------------------------------------------- helpers */

	public static function state(): array {
		$s = get_option( self::SYNC, array() );
		return is_array( $s ) ? $s + array( 'stos' => array(), 'history' => array() ) : array( 'stos' => array(), 'history' => array() );
	}

	/** Records the failure (the last good data stays) and throws, so the runner shows the real reason. */
	private static function fail( string $part, string $error ): void {
		$state                    = self::state();
		$state[ $part ]['error']  = mb_substr( $error, 0, 300 );
		update_option( self::SYNC, $state, false );
		throw new \RuntimeException( $error );
	}

	private static function money( array $b ): array {
		return array( 'gross' => round( $b['minor'] / 100, 2 ), 'count' => (int) $b['count'] );
	}

	private static function shift( string $ym, int $by ): string {
		return gmdate( 'Y-m', strtotime( $ym . '-01 ' . ( $by >= 0 ? '+' : '' ) . $by . ' month' ) );
	}

	private static function months_between( string $a, string $b ): int {
		return ( (int) substr( $b, 0, 4 ) - (int) substr( $a, 0, 4 ) ) * 12 + (int) substr( $b, 5, 2 ) - (int) substr( $a, 5, 2 );
	}

	private static function date( $v ): ?string {
		$v = trim( (string) $v );
		return preg_match( '/^(\d{4}-\d{2}-\d{2})/', $v, $m ) && '0000-00-00' !== $m[1] ? $m[1] : null;
	}

	private static function year( int $y ): int {
		return $y < 100 ? 2000 + $y : $y;
	}
}
