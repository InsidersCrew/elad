<?php
namespace Insiders\Collections\Integrations\Tranzila;

use Insiders\Collections\Engine\Inbox;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Reconciliation (§8, §16): pages through the reports (never stops at page one,
 * AT41), with an overlap window, and re-queues events waiting for verification.
 * Payments are keyed by transaction id, so a transaction seen in a report and in
 * a webhook is still one payment — reports are never summed.
 */
final class Reconciler {
	private const PAGE = 500;
	private const MAX_PAGES = 60;

	public static function incremental(): array {
		return self::scan( Clock::local_date( Clock::now() - DAY_IN_SECONDS ), Clock::today() );
	}

	public static function daily_full(): array {
		return self::scan( Clock::local_date( Clock::now() - 7 * DAY_IN_SECONDS ), Clock::today() );
	}

	public static function scan( string $from, string $to ): array {
		$seen   = array();
		$errors = array();
		$pages  = 0;
		foreach ( Processor::terminals() as $terminal ) {
			for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
				$r = Client::transactions( $terminal, array( 'transaction_start_date' => $from, 'transaction_end_date' => $to ), $page, self::PAGE );
				++$pages;
				if ( ! $r['ok'] ) {
					$errors[] = $terminal . ': ' . $r['error'];
					break;
				}
				foreach ( $r['rows'] as $row ) {
					$n = Client::normalize_report_row( $row );
					if ( '' !== $n['index'] ) {
						$seen[ $terminal . ':' . $n['index'] ] = $n;
					}
				}
				if ( count( $r['rows'] ) < self::PAGE ) {
					break;
				}
			}
		}
		if ( $errors ) {
			// A failed scan must not look like an empty day: throw so the runner records an error, not a success.
			throw new \RuntimeException( 'reconcile failed: ' . implode( ' | ', $errors ) );
		}
		// Events that waited for verification get another pass now that the report may contain them.
		// Only events whose transaction now appears in the report: no API storm for events that never will.
		$waiting  = Db::rows( 'SELECT id, payload_redacted FROM ' . Db::t( 'inbox_events' ) . " WHERE provider = 'tranzila' AND processing_state = 'needs_verification' AND received_at >= %s", Clock::utc( Clock::now() - 8 * DAY_IN_SECONDS ) );
		$requeued = 0;
		foreach ( $waiting as $w ) {
			$p = (array) json_decode( (string) $w['payload_redacted'], true );
			if ( isset( $seen[ (string) ( $p['supplier'] ?? '' ) . ':' . (string) ( $p['index'] ?? '' ) ] ) ) {
				Db::update( 'inbox_events', array( 'processing_state' => 'received' ), array( 'id' => (int) $w['id'] ) );
				++$requeued;
			}
		}
		// Successful report transactions that carry an STO id but never arrived as a notification.
		$missing = 0;
		foreach ( $seen as $key => $n ) {
			if ( '' === $n['sto_id'] || '000' !== $n['response'] ) {
				continue;
			}
			[ $terminal, $index ] = explode( ':', $key, 2 );
			$known = Db::value( 'SELECT id FROM ' . Db::t( 'payments' ) . " WHERE provider = 'tranzila' AND terminal = %s AND transaction_id = %s", $terminal, $index );
			if ( $known ) {
				continue;
			}
			$payload = array( 'supplier' => $terminal, 'index' => $index, 'sto_external_id' => $n['sto_id'], 'sum' => number_format( (int) $n['amount_minor'] / 100, 2, '.', '' ), 'currency' => $n['currency'], 'Response' => $n['response'], 'tranmode' => $n['tranmode'], 'source' => 'report' );
			Inbox::store( 'tranzila', 'sto', wp_json_encode( $payload ), 'application/json', $payload, hash( 'sha256', 'report:' . $key ), Clock::utc() );
			++$missing;
		}
		return array( 'pages' => $pages, 'rows' => count( $seen ), 'requeued' => $requeued, 'missing_notifications' => $missing, 'pr_from_report' => self::payment_requests_from_report( $seen ) );
	}

	/**
	 * Tranzila sends no Notify for a payment-request payment on the sales terminal (₪1 test, 2026-10-20),
	 * and the report row carries no pr_id. It does carry the client's phone, copied from the request into
	 * a user_defined field. A successful sale is matched to an open request of ours only when exactly one
	 * request has the same amount and the same phone; anything less certain is left alone.
	 */
	private static function payment_requests_from_report( array $seen ): int {
		$terminal = (string) \Insiders\Collections\Support\Settings::get( 'tranzila_pr_terminal', 'insiders' );
		$field    = (string) \Insiders\Collections\Support\Settings::get( 'tranzila_pr_match_field' ) ?: 'pr_id';
		$open     = Db::rows(
			'SELECT r.id, r.provider_pr_id, r.amount_minor, r.currency, u.phone_e164 FROM ' . Db::t( 'payment_requests' ) . ' r JOIN ' . Db::t( 'cases' ) . ' c ON c.id = r.case_id JOIN ' . Db::t( 'customers' ) . " u ON u.id = c.customer_id
			 WHERE r.status = 'active' AND r.provider_pr_id IS NOT NULL AND u.phone_e164 IS NOT NULL AND r.created_at >= %s",
			Clock::utc( Clock::now() - 30 * DAY_IN_SECONDS )
		);
		if ( ! $open ) {
			return 0;
		}
		$found = 0;
		foreach ( $seen as $key => $n ) {
			[ $t, $index ] = explode( ':', $key, 2 );
			if ( $t !== $terminal || '000' !== $n['response'] || 'DEBIT' !== strtoupper( (string) ( $n['raw']['txn_type'] ?? 'DEBIT' ) ) || '' !== $n['sto_id'] ) {
				continue;
			}
			if ( Db::value( 'SELECT id FROM ' . Db::t( 'payments' ) . " WHERE provider = 'tranzila' AND terminal = %s AND transaction_id = %s", $t, $index ) ) {
				continue;
			}
			$phones = array();
			foreach ( (array) $n['raw'] as $k => $v ) {
				$d = preg_replace( '/\D/', '', (string) $v );
				if ( str_starts_with( (string) $k, 'user_defined_' ) && strlen( $d ) >= 9 ) {
					$phones[] = substr( $d, -9 );
				}
			}
			$match = array_values( array_filter( $open, static fn( $r ) => (int) $r['amount_minor'] === (int) $n['amount_minor'] && in_array( substr( preg_replace( '/\D/', '', $r['phone_e164'] ), -9 ), $phones, true ) ) );
			if ( 1 !== count( $match ) ) {
				continue;
			}
			$payload = array( 'supplier' => $t, 'index' => $index, $field => $match[0]['provider_pr_id'], 'sum' => number_format( (int) $n['amount_minor'] / 100, 2, '.', '' ), 'currency' => $n['currency'] ?: $match[0]['currency'], 'Response' => '000', 'tranmode' => $n['tranmode'], 'source' => 'report' );
			Inbox::store( 'tranzila', 'pr', wp_json_encode( $payload ), 'application/json', $payload, hash( 'sha256', 'report-pr:' . $key ), Clock::utc() );
			++$found;
		}
		return $found;
	}
}
