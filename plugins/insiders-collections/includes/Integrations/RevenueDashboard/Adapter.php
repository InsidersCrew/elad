<?php
namespace Insiders\Collections\Integrations\RevenueDashboard;

use Insiders\Collections\Domain\Exceptions;
use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bridge to the revenue / P&L dashboard plugin, which already tracks how long a
 * beginner-program student has left before the non-open charge applies.
 *
 * Inbound (candidates): read-only. The dashboard tells us who passed the deadline
 * without opening an account; a person decides whether to open a draft debt (§1:
 * "המערכת אינה קובעת בעצמה אם תלמיד חייב"). Two ways to read, in order:
 *   1. the filter contract 'icol_beginner_program_candidates' — a ~15-line shim in
 *      the dashboard plugin returns rows in the shape documented in docs/;
 *   2. user_meta keys mapped in settings (the house pattern: another plugin can
 *      read user_meta with no code dependency). ?icol_diag=revenue_probe lists
 *      candidate keys with counts so the mapping takes one round, not three.
 *
 * Outbound: icol_get_collection_summary() and the 'icol_payment_allocated' action
 * give the dashboard collected / waived / outstanding amounts by source.
 */
final class Adapter {

	/** Normalized candidate rows. Empty array with 'source' = 'none' when nothing is connected. */
	public static function candidates(): array {
		$horizon = (int) Settings::get( 'revenue_candidate_horizon_days' );
		$rows    = apply_filters( 'icol_beginner_program_candidates', null, array( 'until' => gmdate( 'Y-m-d', Clock::now() + $horizon * DAY_IN_SECONDS ) ) );
		if ( is_array( $rows ) ) {
			return array( 'source' => 'filter', 'rows' => array_values( array_filter( array_map( array( self::class, 'normalize' ), $rows ) ) ) );
		}
		$deadline_key = (string) Settings::get( 'revenue_meta_deadline' );
		if ( '' === $deadline_key ) {
			return array( 'source' => 'none', 'rows' => array() );
		}
		$q = new \WP_User_Query(
			array(
				'meta_query' => array(
					array(
						'key'     => $deadline_key,
						'value'   => gmdate( 'Y-m-d', Clock::now() + $horizon * DAY_IN_SECONDS ),
						'compare' => '<=',
						'type'    => 'DATE',
					),
				),
				'number'     => 2000,
				'fields'     => 'all',
			)
		);
		$out = array();
		foreach ( $q->get_results() as $u ) {
			$opened_key = (string) Settings::get( 'revenue_meta_opened' );
			$opened_raw = '' !== $opened_key ? get_user_meta( $u->ID, $opened_key, true ) : '';
			$out[]      = self::normalize(
				array(
					'wp_user_id'      => $u->ID,
					'name'            => $u->display_name,
					'email'           => $u->user_email,
					'phone'           => (string) get_user_meta( $u->ID, (string) Settings::get( 'revenue_meta_phone', 'billing_phone' ), true ),
					'joined_at'       => '' !== (string) Settings::get( 'revenue_meta_joined' ) ? (string) get_user_meta( $u->ID, (string) Settings::get( 'revenue_meta_joined' ), true ) : '',
					'deadline'        => (string) get_user_meta( $u->ID, $deadline_key, true ),
					'extension_until' => '' !== (string) Settings::get( 'revenue_meta_extension' ) ? (string) get_user_meta( $u->ID, (string) Settings::get( 'revenue_meta_extension' ), true ) : '',
					'account_opened'  => '' === $opened_key ? null : in_array( strtolower( (string) $opened_raw ), array( '1', 'yes', 'true', 'opened', 'כן' ), true ),
				)
			);
		}
		return array( 'source' => 'meta', 'rows' => array_values( array_filter( $out ) ) );
	}

	public static function normalize( $r ): ?array {
		if ( ! is_array( $r ) || empty( $r['wp_user_id'] ) || ! is_numeric( $r['wp_user_id'] ) ) {
			return null; // a contract row without a real user id is dropped, never cast to 0/1
		}
		$date = static function ( $v ) {
			$v = trim( (string) $v );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $v ) ) {
				return substr( $v, 0, 10 );
			}
			if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m ) ) {
				return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
			}
			if ( ctype_digit( $v ) && strlen( $v ) >= 9 ) {
				return gmdate( 'Y-m-d', (int) $v );
			}
			return null;
		};
		$deadline  = $date( $r['deadline'] ?? '' );
		$extension = $date( $r['extension_until'] ?? '' );
		$effective = ( $extension && $extension > (string) $deadline ) ? $extension : $deadline;
		return array(
			'wp_user_id'       => (int) $r['wp_user_id'],
			'name'             => (string) ( $r['name'] ?? '' ),
			'email'            => (string) ( $r['email'] ?? '' ),
			'phone'            => (string) ( $r['phone'] ?? '' ),
			'program'          => (string) ( $r['program'] ?? 'תוכנית ליווי למתחילים' ),
			'joined_at'        => $date( $r['joined_at'] ?? '' ),
			'deadline'         => $deadline,
			'extension_until'  => $extension,
			'effective_deadline' => $effective,
			'days_left'        => $effective ? (int) floor( ( strtotime( $effective ) - strtotime( Clock::today() ) ) / DAY_IN_SECONDS ) : null,
			'account_opened'   => array_key_exists( 'account_opened', $r ) ? ( null === $r['account_opened'] ? null : (bool) $r['account_opened'] ) : null,
			'status_checked_at' => $date( $r['status_checked_at'] ?? '' ),
			'suggested_amount_minor' => isset( $r['suggested_amount'] ) ? Money::parse( $r['suggested_amount'] ) : null,
			'agreement_ref'    => (string) ( $r['agreement_ref'] ?? '' ),
		);
	}

	/**
	 * Daily: (1) record new candidates whose deadline passed without an account;
	 * (2) AT: a student with an active non-open case whose account is now open ->
	 * pause outreach and ask a person (opening later does not cancel the debt by itself).
	 */
	public static function sync(): array {
		$c   = self::candidates();
		$new = 0;
		$opened_after = 0;
		foreach ( $c['rows'] as $r ) {
			$key = 'u' . $r['wp_user_id'] . ':' . ( $r['effective_deadline'] ?? 'none' );
			if ( false === $r['account_opened'] && $r['effective_deadline'] && $r['effective_deadline'] < Clock::today() ) {
				global $wpdb;
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO ' . Db::t( 'program_candidates' ) . ' (wp_user_id, candidate_key, deadline, status, snapshot, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s)',
						$r['wp_user_id'],
						$key,
						$r['effective_deadline'],
						'new',
						wp_json_encode( $r, JSON_UNESCAPED_UNICODE ),
						Clock::utc(),
						Clock::utc()
					)
				);
				$new += $wpdb->rows_affected > 0 ? 1 : 0;
			}
			if ( true === $r['account_opened'] ) {
				$cases = Db::rows(
					'SELECT c.* FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'customers' ) . " u ON u.id = c.customer_id WHERE u.wp_user_id = %d AND c.source_type = 'non_open_charge' AND c.workflow_state NOT IN ('closed','human_review')",
					$r['wp_user_id']
				);
				foreach ( $cases as $case ) {
					Scheduler::cancel_for_case( (int) $case['id'], 'account_opened_per_dashboard' );
					if ( 'draft' !== $case['workflow_state'] && Workflow::can( $case['workflow_state'], 'human_review' ) ) {
						Workflow::transition( (int) $case['id'], 'human_review', 'דשבורד ההכנסות מסמן שהחשבון נפתח, נדרשת החלטה', null, array( 'claims_account_opened' => 1 ), 'revenue_dashboard' );
					}
					Exceptions::open( 'opened_after:' . $case['id'], 'account_opened_after_charge', 'medium', 'לפי דשבורד ההכנסות התלמיד פתח חשבון אחרי הקמת החוב', array( 'entity_type' => 'case', 'entity_id' => (int) $case['id'], 'customer_id' => (int) $case['customer_id'] ) );
					++$opened_after;
				}
			}
		}
		// Drafts created from a candidate whose deadline moved (extension granted later) are flagged stale.
		$stale = 0;
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'program_candidates' ) . " WHERE status = 'drafted' AND case_id IS NOT NULL" ) as $pc ) {
			$current = null;
			foreach ( $c['rows'] as $r ) {
				if ( (int) $r['wp_user_id'] === (int) $pc['wp_user_id'] ) {
					$current = $r;
				}
			}
			if ( $current && $current['effective_deadline'] && $current['effective_deadline'] !== $pc['deadline'] ) {
				Exceptions::open( 'deadline_moved:' . $pc['id'], 'account_opened_after_charge', 'medium', 'המועד לפתיחת חשבון השתנה בדשבורד אחרי שנוצרה טיוטת חוב', array( 'entity_type' => 'case', 'entity_id' => (int) $pc['case_id'] ) );
				++$stale;
			}
		}
		return array( 'source' => $c['source'], 'rows' => count( $c['rows'] ), 'new_candidates' => $new, 'opened_after_charge' => $opened_after, 'stale_drafts' => $stale );
	}

	/** Outbound summary for the P&L dashboard. Collections are allocations net of reversals; write-offs are never "collected". */
	public static function collection_summary( string $from, string $to ): array {
		$from_utc = Clock::utc( Clock::local_to_ts( $from, '00:00' ) );
		$to_utc   = Clock::utc( Clock::local_to_ts( $to, '23:59' ) + 59 );
		$by = static function ( string $sql, ...$args ) {
			$out = array();
			foreach ( Db::rows( $sql, ...$args ) as $r ) {
				$out[ $r['source_type'] ][ $r['currency'] ] = (int) $r['total'];
			}
			return $out;
		};
		return array(
			'period'      => array( 'from' => $from, 'to' => $to ),
			'charged'     => $by( 'SELECT c.source_type, d.currency, SUM(d.original_amount_minor) total FROM ' . Db::t( 'debt_items' ) . ' d JOIN ' . Db::t( 'cases' ) . ' c ON c.id = d.case_id WHERE d.approved_at BETWEEN %s AND %s GROUP BY c.source_type, d.currency', $from_utc, $to_utc ),
			'collected'   => $by( 'SELECT c.source_type, d.currency, SUM(a.amount_minor) total FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = a.debt_item_id JOIN ' . Db::t( 'cases' ) . ' c ON c.id = d.case_id WHERE a.created_at BETWEEN %s AND %s GROUP BY c.source_type, d.currency', $from_utc, $to_utc ),
			'written_off' => $by( 'SELECT c.source_type, d.currency, -SUM(j.amount_minor) total FROM ' . Db::t( 'adjustments' ) . ' j JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = j.debt_item_id JOIN ' . Db::t( 'cases' ) . " c ON c.id = d.case_id WHERE j.type IN ('write_off','credit') AND j.created_at BETWEEN %s AND %s GROUP BY c.source_type, d.currency", $from_utc, $to_utc ),
			'outstanding_due_now' => $by( 'SELECT c.source_type, d.currency, SUM(d.cached_balance_minor) total FROM ' . Db::t( 'debt_items' ) . ' d JOIN ' . Db::t( 'cases' ) . " c ON c.id = d.case_id WHERE d.finance_state IN ('open','partially_paid') GROUP BY c.source_type, d.currency" ),
			'units'       => 'minor (agorot)',
		);
	}

	/** Diagnostic: what the dashboard plugin exposes on this site, read rather than reconstructed. */
	public static function probe(): array {
		global $wpdb;
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$data = function_exists( 'get_plugin_data' ) && is_readable( WP_PLUGIN_DIR . '/' . $file ) ? get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false ) : array( 'Name' => $file );
			$plugins[] = array( 'file' => $file, 'name' => $data['Name'] ?? $file, 'version' => $data['Version'] ?? '' );
		}
		$pattern = 'deadline|open|account|trial|program|join|broker|charge|beginner|revenue|pnl|grace|days_left|expire';
		$keys    = $wpdb->get_results(
			$wpdb->prepare( "SELECT meta_key, COUNT(*) AS n, MIN(meta_value) AS sample_min, MAX(meta_value) AS sample_max FROM {$wpdb->usermeta} WHERE meta_key REGEXP %s GROUP BY meta_key ORDER BY n DESC LIMIT 80", $pattern ),
			ARRAY_A
		);
		foreach ( $keys as &$k ) {
			foreach ( array( 'sample_min', 'sample_max' ) as $f ) {
				$v       = (string) $k[ $f ];
				$k[ $f ] = strlen( $v ) > 40 ? substr( $v, 0, 40 ) . '…' : $v;
			}
		}
		$functions = array();
		foreach ( get_defined_functions()['user'] as $fn ) {
			if ( preg_match( '/revenue|pnl|profit|beginner|non_open|nonopen|deadline/i', $fn ) ) {
				$rf          = new \ReflectionFunction( $fn );
				$functions[] = array( 'name' => $fn, 'file' => str_replace( ABSPATH, '', (string) $rf->getFileName() ), 'params' => array_map( static fn( $p ) => '$' . $p->getName(), $rf->getParameters() ) );
			}
		}
		$routes = array();
		if ( function_exists( 'rest_get_server' ) ) {
			foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
				if ( preg_match( '/revenue|pnl|dashboard|profit/i', $route ) ) {
					$routes[] = $route;
				}
			}
		}
		$filters = array( 'icol_beginner_program_candidates' => has_filter( 'icol_beginner_program_candidates' ) ? 'מחובר' : 'לא מחובר' );
		return array( 'active_plugins' => $plugins, 'usermeta_candidates' => $keys, 'functions' => $functions, 'rest_routes' => $routes, 'contract' => $filters, 'mapped' => array( 'deadline' => Settings::get( 'revenue_meta_deadline' ), 'opened' => Settings::get( 'revenue_meta_opened' ), 'joined' => Settings::get( 'revenue_meta_joined' ), 'extension' => Settings::get( 'revenue_meta_extension' ) ) );
	}
}
