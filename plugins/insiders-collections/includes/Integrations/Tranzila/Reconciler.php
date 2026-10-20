<?php
namespace Insiders\Collections\Integrations\Tranzila;

use Insiders\Collections\Engine\Inbox;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Phone;
use Insiders\Collections\Domain\Exceptions;

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
			if ( ! array_filter( $errors, static fn( $e ) => str_starts_with( $e, (string) \Insiders\Collections\Support\Settings::get( 'tranzila_pr_terminal', 'insiders' ) . ':' ) ) ) {
				self::payment_requests_from_report( $seen );
			}
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
			'SELECT r.id, r.provider_pr_id, r.amount_minor, r.currency, r.status, r.created_at, r.expires_at, u.phone_e164 FROM ' . Db::t( 'payment_requests' ) . ' r JOIN ' . Db::t( 'cases' ) . ' c ON c.id = r.case_id JOIN ' . Db::t( 'customers' ) . " u ON u.id = c.customer_id
			 WHERE r.status IN ('active','superseded','expired','paid') AND r.provider_pr_id IS NOT NULL AND u.phone_e164 IS NOT NULL AND r.expires_at >= %s",
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
			// Already queued from an earlier scan, or already with a person (open or resolved): never decided twice.
			// A rep's manual record is stored under another provider, so the exception is the only trace of it.
			if ( Db::value( 'SELECT id FROM ' . Db::t( 'inbox_events' ) . ' WHERE provider_event_key = %s', hash( 'sha256', 'report-pr:' . $key ) )
				|| Db::value( 'SELECT id FROM ' . Db::t( 'exceptions' ) . ' WHERE exception_key = %s', 'pr_report_unmatched:' . $key ) ) {
				continue;
			}
			$phones = array();
			foreach ( (array) $n['raw'] as $k => $v ) {
				$e = str_starts_with( (string) $k, 'user_defined_' ) ? Phone::e164( (string) $v ) : null;
				if ( null !== $e ) {
					$phones[] = $e;
				}
			}
			if ( ! $phones ) {
				continue; // an ordinary sale on the terminal: no phone of a request of ours
			}
			// The report's date is local time; a date alone counts as the end of that day.
			$sale_ts = preg_match( '/^(\d{4}-\d{2}-\d{2})(?:\s+(\d{2}:\d{2}))?/', (string) $n['date'], $dm ) ? (int) Clock::local_to_ts( $dm[1], $dm[2] ?? '23:59' ) : 0;
			// Same phone, same amount, and a sale inside the request's life (from its creation to a day after it expired).
			// Another amount from the same student is an ordinary purchase (a course, a session): not ours.
			$same = array_values(
				array_filter(
					$open,
					static fn( $r ) => in_array( (string) $r['phone_e164'], $phones, true ) && (int) $r['amount_minor'] === (int) $n['amount_minor']
						&& ( ! $sale_ts || ( $sale_ts >= (int) Clock::ts( $r['created_at'] ) - HOUR_IN_SECONDS && $sale_ts <= (int) Clock::ts( $r['expires_at'] ) + DAY_IN_SECONDS ) )
				)
			);
			$active = array_values( array_filter( $same, static fn( $r ) => 'active' === $r['status'] ) );
			$old    = array_values( array_filter( $same, static fn( $r ) => in_array( $r['status'], array( 'superseded', 'expired' ), true ) ) );
			$paid   = array_values( array_filter( $same, static fn( $r ) => 'paid' === $r['status'] ) );
			$match  = null;
			if ( 1 === count( $active ) ) {
				$match = $active[0];
			} elseif ( ! $active && 1 === count( $old ) && ! $paid ) {
				$match = $old[0]; // paid on a link that was replaced meanwhile (the same debt, the same amount)
			} elseif ( $same ) {
				// More than one request fits, or this one was already paid (a second payment?): a person decides.
				Exceptions::open( 'pr_report_unmatched:' . $key, 'payment_without_debt', 'medium', 'תשלום של ' . Money::format( (int) $n['amount_minor'] ) . ' ₪ בדוח העסקאות מטלפון של תלמיד, ' . ( $paid && ! $active && ! $old ? 'בסכום של בקשת תשלום ששולמה כבר (ייתכן תשלום כפול)' : 'שמתאים ליותר מבקשת תשלום אחת' ) . '. נדרש שיוך ידני', array( 'details' => array( 'terminal' => $t, 'index' => $index, 'requests' => array_column( $same, 'id' ) ) ) );
			}
			if ( ! $match ) {
				continue;
			}
			$match = array( $match );
			$payload = array( 'supplier' => $t, 'index' => $index, $field => $match[0]['provider_pr_id'], 'sum' => number_format( (int) $n['amount_minor'] / 100, 2, '.', '' ), 'currency' => $n['currency'] ?: $match[0]['currency'], 'Response' => '000', 'tranmode' => $n['tranmode'], 'source' => 'report' );
			Inbox::store( 'tranzila', 'pr', wp_json_encode( $payload ), 'application/json', $payload, hash( 'sha256', 'report-pr:' . $key ), Clock::utc() );
			++$found;
		}
		return $found;
	}
}
