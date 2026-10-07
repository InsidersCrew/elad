<?php
namespace Insiders\Collections\Integrations\Tranzila;

use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Which billing cycle does a My Billing event belong to?
 *
 * The notification has no cycle / installment field (verified 2026-10), and
 * "event date + amount" cannot tell a retry from the next month's charge (§4).
 * So the default is: nobody guesses. Strategies:
 *  - manual:   always null -> the event waits for a person (AT05)
 *  - field:    a payload field Tranzila confirmed identifies the cycle
 *  - schedule: map the event date onto the order's charge schedule (charge_dom).
 *              A failure on a scheduled day opens that cycle; an event between
 *              scheduled days is a retry only when exactly ONE open failed cycle
 *              exists for the order. Anything else -> null.
 */
final class Cycle {

	public static function resolve( array $order, array $payload, int $event_ts, bool $is_failure ): ?string {
		$strategy = (string) Settings::get( 'tranzila_cycle_strategy' );
		if ( 'field' === $strategy ) {
			$field = (string) Settings::get( 'tranzila_cycle_field' );
			$v     = '' !== $field ? trim( (string) ( $payload[ $field ] ?? '' ) ) : '';
			return '' !== $v ? 'f:' . $v : null;
		}
		if ( 'schedule' !== $strategy ) {
			return null;
		}
		$dom = (int) ( $order['charge_dom'] ?? 0 );
		if ( $dom < 1 || $dom > 31 ) {
			return null;
		}
		$local = Clock::local( $event_ts );
		$scheduled_this_month = self::scheduled_date( (int) $local->format( 'Y' ), (int) $local->format( 'n' ), $dom );
		$date  = $local->format( 'Y-m-d' );
		$tolerance = 1; // provider runs can cross midnight
		$diff = abs( ( strtotime( $date ) - strtotime( $scheduled_this_month ) ) / DAY_IN_SECONDS );
		if ( $diff <= $tolerance ) {
			return 's:' . substr( $scheduled_this_month, 0, 7 );
		}
		// Off-schedule event: a retry. Accept only an unambiguous single open failed cycle.
		$open = Db::rows(
			'SELECT d.source_key FROM ' . Db::t( 'debt_items' ) . ' d JOIN ' . Db::t( 'cases' ) . " c ON c.id = d.case_id WHERE c.recurring_order_id = %d AND d.source_key LIKE %s AND d.finance_state IN ('open','partially_paid','not_due','review')",
			(int) $order['id'],
			'tz:%'
		);
		if ( 1 === count( $open ) ) {
			$parts = explode( ':', $open[0]['source_key'] );
			return implode( ':', array_slice( $parts, 3 ) );
		}
		return null;
	}

	/** Scheduled charge date for a month, clamped to the month's last day (charge_dom 31 in February). */
	public static function scheduled_date( int $y, int $m, int $dom ): string {
		$last = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
		return sprintf( '%04d-%02d-%02d', $y, $m, min( $dom, $last ) );
	}

	public static function source_key( string $terminal, string $sto_id, string $cycle ): string {
		return 'tz:' . $terminal . ':' . $sto_id . ':' . $cycle;
	}
}
