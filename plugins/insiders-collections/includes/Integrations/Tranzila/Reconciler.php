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
		return array( 'pages' => $pages, 'rows' => count( $seen ), 'requeued' => $requeued, 'missing_notifications' => $missing );
	}
}
