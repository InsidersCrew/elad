<?php
namespace Insiders\Collections\Integrations\RevenueDashboard;

use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only reader for insiders-finance-dashboard (IFD, 0.63.x): the 90-day
 * commitment clock lives in {prefix}ifd_commitments, keyed by Pipedrive person
 * and deal, not by WordPress user.
 *
 * The contract is IFD's own derivation, copied here on purpose and in one place:
 *  - status is derived on read (IFD stores no status column): unresolved and
 *    DATEDIFF(today, signed_at) > WINDOW_DAYS means expired;
 *  - rows with signed_source 'resolution_first' or 'reverted' are not cohort
 *    students (IFD excludes them in every query; so do we);
 *  - only signed_at >= IFD_BASELINE_DATE belongs to the new process;
 *  - resolution_kind 'attributed'/'unattributed' = account opened, 'fixed' = the
 *    non-open charge was recorded as paid (a won Pipedrive deal with the penalty product).
 *
 * IFD reads Pipedrive hourly. When its last good read is old, an unresolved row
 * may simply be a resolution we have not seen yet, so a stale dashboard must
 * never turn into new candidates (a sync failure is not "nobody opened").
 */
final class FinanceDashboard {

	private const REQUIRED = array(
		'commitments' => array( 'person_id', 'deal_id', 'signed_at', 'resolved_at', 'resolution_kind', 'signed_source', 'owner_name', 'class_cohort' ),
		'leads'       => array( 'person_id', 'person_name' ),
		'snapshots'   => array( 'source_key', 'ok', 'ok_at', 'attempt_at', 'fail_reason' ),
	);

	private static ?array $contract = null;

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'ifd_' . $name;
	}

	/** Columns read from information_schema, not assumed from the IFD version. Cached per request. */
	public static function contract(): array {
		if ( null !== self::$contract ) {
			return self::$contract;
		}
		global $wpdb;
		$missing = array();
		foreach ( self::REQUIRED as $t => $cols ) {
			$have = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', self::table( $t ) ) );
			if ( ! $have ) {
				$missing[] = self::table( $t ) . ' (טבלה)';
				continue;
			}
			foreach ( array_diff( $cols, $have ) as $c ) {
				$missing[] = self::table( $t ) . '.' . $c;
			}
		}
		$snap   = $missing ? null : $wpdb->get_row( $wpdb->prepare( 'SELECT ok, ok_at, attempt_at, fail_reason FROM ' . self::table( 'snapshots' ) . ' WHERE source_key = %s', 'pipedrive_deals' ), ARRAY_A );
		$ok_at  = $snap && $snap['ok_at'] ? (string) $snap['ok_at'] : null;
		$age_h  = $ok_at ? round( ( Clock::now() - strtotime( $ok_at . ' UTC' ) ) / HOUR_IN_SECONDS, 1 ) : null;
		$max_h  = max( 2, (int) Settings::get( 'revenue_max_staleness_hours' ) );
		self::$contract = array(
			'available'      => ! $missing,
			'missing'        => $missing,
			'ifd_version'    => defined( 'IFD_VERSION' ) ? IFD_VERSION : null,
			'window_days'    => self::window_days(),
			'baseline'       => self::baseline(),
			'sync_ok_at'     => $ok_at,
			'sync_age_hours' => $age_h,
			'last_attempt_ok' => $snap ? (bool) (int) $snap['ok'] : null,
			'fail_reason'    => $snap['fail_reason'] ?? null,
			'max_age_hours'  => $max_h,
			// Never ran, ran too long ago, or the last attempt failed: all three mean "do not trust unresolved".
			'stale'          => null === $age_h || $age_h > $max_h || ( $snap && ! (int) $snap['ok'] ),
		);
		return self::$contract;
	}

	public static function flush(): void {
		self::$contract = null;
	}

	public static function available(): bool {
		return self::contract()['available'];
	}

	public static function window_days(): int {
		return class_exists( '\IFD_Commitments' ) && defined( 'IFD_Commitments::WINDOW_DAYS' ) ? (int) \IFD_Commitments::WINDOW_DAYS : 90;
	}

	public static function baseline(): string {
		return defined( 'IFD_BASELINE_DATE' ) ? (string) IFD_BASELINE_DATE : '2026-08-01';
	}

	/**
	 * Unresolved commitments whose deadline (signed_at + window) is on or before $until.
	 * Shaped for Adapter::normalize(). Contact details are not in IFD; they come from
	 * Pipedrive (Adapter::enrich) when a person looks at the candidate.
	 */
	public static function unresolved_until( string $until ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.person_id, c.deal_id, c.signed_at, c.class_cohort, c.owner_name, c.signed_source, l.person_name
				 FROM ' . self::table( 'commitments' ) . ' c
				 LEFT JOIN ' . self::table( 'leads' ) . " l ON l.person_id = c.person_id
				 WHERE c.resolved_at IS NULL
				   AND c.signed_at >= %s
				   AND (c.signed_source IS NULL OR c.signed_source NOT IN ('resolution_first','reverted'))
				   AND DATE_ADD(c.signed_at, INTERVAL %d DAY) <= %s
				 ORDER BY c.signed_at ASC
				 LIMIT 5000",
				self::baseline(),
				self::window_days(),
				$until
			),
			ARRAY_A
		);
		$contract = self::contract();
		$penalty  = self::penalty_gross();
		$out      = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'source'              => 'finance_dashboard',
				'pipedrive_person_id' => (int) $r['person_id'],
				'pipedrive_deal_id'   => (int) $r['deal_id'],
				'name'                => (string) $r['person_name'],
				'owner_name'          => (string) $r['owner_name'],
				'class_cohort'        => (string) $r['class_cohort'],
				'program'             => 'תוכנית ליווי למתחילים',
				'joined_at'           => (string) $r['signed_at'],
				'deadline'            => gmdate( 'Y-m-d', strtotime( $r['signed_at'] . ' UTC' ) + self::window_days() * DAY_IN_SECONDS ),
				'account_opened'      => false,
				'status_checked_at'   => $contract['sync_ok_at'] ? substr( $contract['sync_ok_at'], 0, 10 ) : '',
				'suggested_amount'    => null !== $penalty ? Money::format( $penalty ) : '',
				'agreement_ref'       => 'Pipedrive deal ' . (int) $r['deal_id'],
			);
		}
		return $out;
	}

	/**
	 * Resolutions for people we already work on: by deal (exact) and, for cases
	 * without a deal, the latest per person. kind: opened | paid.
	 */
	public static function resolutions( array $person_ids ): array {
		$person_ids = array_values( array_unique( array_filter( array_map( 'intval', $person_ids ) ) ) );
		$out        = array( 'by_deal' => array(), 'by_person' => array() );
		if ( ! $person_ids ) {
			return $out;
		}
		global $wpdb;
		$in   = implode( ',', $person_ids );
		$rows = $wpdb->get_results(
			'SELECT person_id, deal_id, resolved_at, resolution_kind FROM ' . self::table( 'commitments' ) . "
			 WHERE person_id IN ($in) AND resolved_at IS NOT NULL
			   AND (signed_source IS NULL OR signed_source NOT IN ('resolution_first','reverted'))
			 ORDER BY resolved_at ASC",
			ARRAY_A
		);
		foreach ( (array) $rows as $r ) {
			$hit = array(
				'kind'        => 'fixed' === $r['resolution_kind'] ? 'paid' : 'opened',
				'resolved_at' => (string) $r['resolved_at'],
				'deal_id'     => (int) $r['deal_id'],
			);
			$out['by_deal'][ (int) $r['deal_id'] ]     = $hit;
			$out['by_person'][ (int) $r['person_id'] ] = $hit;
		}
		return $out;
	}

	/** Current resolution of one enrollment deal, or null while it is still open. */
	public static function deal_resolution( int $person_id, int $deal_id ): ?array {
		if ( ! self::available() ) {
			return null;
		}
		return self::resolutions( array( $person_id ) )['by_deal'][ $deal_id ] ?? null;
	}

	/** The penalty product price IFD pulled from the Pipedrive catalog (gross, includes VAT), in agorot. */
	public static function penalty_gross(): ?int {
		$p   = get_option( 'ifd_penalty_price', null );
		$pid = (int) get_option( 'ifd_penalty_product_id', 0 ); // IFD keeps each setting as its own ifd_* option
		if ( class_exists( '\IFD_Settings' ) && method_exists( '\IFD_Settings', 'penalty_price' ) ) {
			$p = \IFD_Settings::penalty_price();
		} elseif ( ! is_array( $p ) || ! $pid || (int) ( $p['product_id'] ?? 0 ) !== $pid ) {
			$p = null; // same rule as IFD: a price pulled for another product is not valid
		}
		if ( ! is_array( $p ) || empty( $p['gross'] ) || ( isset( $p['currency'] ) && 'ILS' !== strtoupper( (string) $p['currency'] ) ) ) {
			return null;
		}
		return Money::from_provider( $p['gross'] );
	}

	/** Counts for the probe: zero must be visible as zero, and "not installed" must not look like zero. */
	public static function counts(): array {
		if ( ! self::available() ) {
			return array();
		}
		global $wpdb;
		$today = Clock::today();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) total,
				        COALESCE(SUM(resolved_at IS NULL AND DATEDIFF(%s, signed_at) <= %d),0) pending,
				        COALESCE(SUM(resolved_at IS NULL AND DATEDIFF(%s, signed_at) > %d),0) expired,
				        COALESCE(SUM(resolution_kind IN ('attributed','unattributed')),0) opened,
				        COALESCE(SUM(resolution_kind = 'fixed'),0) paid,
				        MIN(CASE WHEN resolved_at IS NULL THEN DATE_ADD(signed_at, INTERVAL %d DAY) END) next_deadline
				 FROM " . self::table( 'commitments' ) . "
				 WHERE signed_at >= %s AND (signed_source IS NULL OR signed_source NOT IN ('resolution_first','reverted'))",
				$today,
				self::window_days(),
				$today,
				self::window_days(),
				self::window_days(),
				self::baseline()
			),
			ARRAY_A
		);
		return array_map( static fn( $v ) => is_numeric( $v ) ? (int) $v : $v, (array) $row );
	}
}
