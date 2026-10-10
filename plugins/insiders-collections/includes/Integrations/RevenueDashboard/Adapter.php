<?php
namespace Insiders\Collections\Integrations\RevenueDashboard;

use Insiders\Collections\Domain\Exceptions;
use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Integrations\Pipedrive\Client as Pipedrive;
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
 * "המערכת אינה קובעת בעצמה אם תלמיד חייב"). Three ways to read, in order:
 *   1. the filter contract 'icol_beginner_program_candidates' (explicit override);
 *   2. insiders-finance-dashboard's own tables, read directly (FinanceDashboard) —
 *      the real dashboard, keyed by Pipedrive person, needs no code change on its side;
 *   3. user_meta keys mapped in settings. ?icol_diag=revenue_probe shows which one is live.
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
		if ( FinanceDashboard::available() ) {
			$until = gmdate( 'Y-m-d', strtotime( Clock::today() . ' UTC' ) + $horizon * DAY_IN_SECONDS );
			return array(
				'source'   => 'finance_dashboard',
				'contract' => FinanceDashboard::contract(),
				'rows'     => array_values( array_filter( array_map( array( self::class, 'normalize' ), FinanceDashboard::unresolved_until( $until ) ) ) ),
			);
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
		$has_user   = ! empty( $r['wp_user_id'] ) && is_numeric( $r['wp_user_id'] );
		$has_person = ! empty( $r['pipedrive_person_id'] ) && is_numeric( $r['pipedrive_person_id'] );
		if ( ! is_array( $r ) || ( ! $has_user && ! $has_person ) ) {
			return null; // a row without a real identity is dropped, never cast to 0/1
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
			'source'           => (string) ( $r['source'] ?? '' ),
			'wp_user_id'       => $has_user ? (int) $r['wp_user_id'] : null,
			'pipedrive_person_id' => $has_person ? (int) $r['pipedrive_person_id'] : null,
			'pipedrive_deal_id' => ! empty( $r['pipedrive_deal_id'] ) && is_numeric( $r['pipedrive_deal_id'] ) ? (int) $r['pipedrive_deal_id'] : null,
			'owner_name'       => (string) ( $r['owner_name'] ?? '' ),
			'class_cohort'     => (string) ( $r['class_cohort'] ?? '' ),
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

	/** Stable identity of a candidate row across runs. */
	public static function candidate_key( array $r ): string {
		if ( ! empty( $r['pipedrive_person_id'] ) ) {
			return 'pd' . $r['pipedrive_person_id'] . ':' . ( $r['pipedrive_deal_id'] ?: 'none' );
		}
		return 'u' . $r['wp_user_id'] . ':' . ( $r['effective_deadline'] ?? 'none' );
	}

	/**
	 * Daily: (1) record new candidates whose deadline passed without an account;
	 * (2) a student with an active non-open case whose account is now open, or whose
	 * charge was recorded as paid elsewhere -> stop outreach and ask a person
	 * (opening later does not cancel the debt by itself; a payment we did not see is
	 * not ours to confirm).
	 * A stale finance dashboard records nothing: an unresolved row may be a resolution
	 * it has not read yet.
	 */
	public static function sync(): array {
		$c = self::candidates();
		if ( 'finance_dashboard' === $c['source'] && ! empty( $c['contract']['stale'] ) ) {
			$ct = $c['contract'];
			Exceptions::open(
				'ifd_stale:' . Clock::today(),
				'integration_failure',
				'medium',
				'דשבורד ההכנסות לא סנכרן את פייפדרייב ' . ( null === $ct['sync_age_hours'] ? '(אין סנכרון מוצלח)' : $ct['sync_age_hours'] . ' שעות' ) . '. מועמדים חדשים לא נקלטו עד שהסנכרון יתעדכן.',
				array( 'details' => array( 'sync_ok_at' => $ct['sync_ok_at'], 'fail_reason' => $ct['fail_reason'] ) )
			);
			return array( 'source' => $c['source'], 'rows' => count( $c['rows'] ), 'stale' => true, 'new_candidates' => 0, 'opened_after_charge' => 0, 'paid_elsewhere' => 0, 'stale_drafts' => 0 );
		}
		$new      = 0;
		$new_ids  = array();
		global $wpdb;
		foreach ( $c['rows'] as $r ) {
			if ( false === $r['account_opened'] && $r['effective_deadline'] && $r['effective_deadline'] < Clock::today() ) {
				$wpdb->query(
					$wpdb->prepare(
						'INSERT IGNORE INTO ' . Db::t( 'program_candidates' ) . ' (wp_user_id, source, pipedrive_person_id, pipedrive_deal_id, candidate_key, deadline, status, snapshot, created_at, updated_at) VALUES (' . ( $r['wp_user_id'] ? (int) $r['wp_user_id'] : 'NULL' ) . ', %s, ' . ( $r['pipedrive_person_id'] ? (int) $r['pipedrive_person_id'] : 'NULL' ) . ', ' . ( $r['pipedrive_deal_id'] ? (int) $r['pipedrive_deal_id'] : 'NULL' ) . ', %s, %s, %s, %s, %s, %s)',
						$c['source'],
						self::candidate_key( $r ),
						$r['effective_deadline'],
						'new',
						wp_json_encode( $r, JSON_UNESCAPED_UNICODE ),
						Clock::utc(),
						Clock::utc()
					)
				);
				if ( $wpdb->rows_affected > 0 ) {
					++$new;
					$new_ids[] = (int) $wpdb->insert_id;
				}
			}
		}

		// Active non-open cases and what the dashboard now says about each student.
		$active = Db::rows( 'SELECT c.*, u.wp_user_id, u.pipedrive_person_id, a.pipedrive_deal_id FROM ' . Db::t( 'cases' ) . ' c JOIN ' . Db::t( 'customers' ) . ' u ON u.id = c.customer_id LEFT JOIN ' . Db::t( 'agreements' ) . " a ON a.id = c.agreement_id WHERE c.source_type = 'non_open_charge' AND c.phase = 'charge' AND c.workflow_state NOT IN ('closed','human_review')" );
		$status = array(); // case id => opened|paid
		if ( 'finance_dashboard' === $c['source'] ) {
			$waiting = Db::rows( 'SELECT id, pipedrive_person_id, pipedrive_deal_id FROM ' . Db::t( 'program_candidates' ) . " WHERE status = 'new' AND pipedrive_deal_id IS NOT NULL" );
			$res     = FinanceDashboard::resolutions( array_merge( array_column( $active, 'pipedrive_person_id' ), array_column( $waiting, 'pipedrive_person_id' ) ) );
			foreach ( $active as $case ) {
				// The enrollment deal decides, whenever it resolved: a student who opened between the
				// candidate list and the draft must not get reminders. Without a deal, only resolutions
				// after the case was opened count (an older enrollment says nothing about this charge).
				$hit = $case['pipedrive_deal_id'] ? ( $res['by_deal'][ (int) $case['pipedrive_deal_id'] ] ?? null ) : ( $res['by_person'][ (int) $case['pipedrive_person_id'] ] ?? null );
				if ( $hit && ( $case['pipedrive_deal_id'] || $hit['resolved_at'] >= substr( (string) $case['created_at'], 0, 10 ) ) ) {
					$status[ (int) $case['id'] ] = $hit['kind'];
				}
			}
			// Candidates nobody decided on yet, whose deal resolved since: they leave the list on their own.
			foreach ( $waiting as $pc ) {
				$hit = $res['by_deal'][ (int) $pc['pipedrive_deal_id'] ] ?? null;
				if ( $hit ) {
					Db::update( 'program_candidates', array( 'status' => 'resolved', 'note' => 'opened' === $hit['kind'] ? 'נפתח חשבון לפי דשבורד ההכנסות' : 'שולם לפי דשבורד ההכנסות', 'updated_at' => Clock::utc() ), array( 'id' => (int) $pc['id'] ) );
				}
			}
		} else {
			$opened_users = array();
			foreach ( $c['rows'] as $r ) {
				if ( true === $r['account_opened'] && $r['wp_user_id'] ) {
					$opened_users[ (int) $r['wp_user_id'] ] = true;
				}
			}
			foreach ( $active as $case ) {
				if ( isset( $opened_users[ (int) $case['wp_user_id'] ] ) ) {
					$status[ (int) $case['id'] ] = 'opened';
				}
			}
		}
		$opened_after = 0;
		$paid_elsewhere = 0;
		foreach ( $active as $case ) {
			$kind = $status[ (int) $case['id'] ] ?? null;
			if ( ! $kind ) {
				continue;
			}
			$balance = (int) Db::value( 'SELECT COALESCE(SUM(cached_balance_minor),0) FROM ' . Db::t( 'debt_items' ) . " WHERE case_id = %d AND finance_state NOT IN ('settled','cancelled')", (int) $case['id'] );
			if ( 'paid' === $kind && $balance <= 0 ) {
				continue; // we collected it ourselves; the dashboard simply caught up
			}
			Scheduler::cancel_for_case( (int) $case['id'], 'opened' === $kind ? 'account_opened_per_dashboard' : 'paid_per_dashboard' );
			if ( 'draft' !== $case['workflow_state'] && Workflow::can( $case['workflow_state'], 'human_review' ) ) {
				Workflow::transition(
					(int) $case['id'],
					'human_review',
					'opened' === $kind ? 'דשבורד ההכנסות מסמן שהחשבון נפתח, נדרשת החלטה' : 'דשבורד ההכנסות מסמן שהחיוב שולם, נדרש אימות',
					null,
					'opened' === $kind ? array( 'claims_account_opened' => 1 ) : array(),
					'revenue_dashboard'
				);
			}
			if ( 'opened' === $kind ) {
				Exceptions::open( 'opened_after:' . $case['id'], 'account_opened_after_charge', 'medium', 'לפי דשבורד ההכנסות התלמיד פתח חשבון אחרי הקמת החוב', array( 'entity_type' => 'case', 'entity_id' => (int) $case['id'], 'customer_id' => (int) $case['customer_id'] ) );
				++$opened_after;
			} else {
				Exceptions::open( 'paid_elsewhere:' . $case['id'], 'paid_per_crm', 'high', 'לפי דשבורד ההכנסות החיוב נרשם כשולם בפייפדרייב, אבל במערכת התשלומים לא התקבל תשלום. התזכורות נעצרו.', array( 'entity_type' => 'case', 'entity_id' => (int) $case['id'], 'customer_id' => (int) $case['customer_id'] ) );
				++$paid_elsewhere;
			}
		}

		// Drafts created from a candidate whose deadline moved (extension granted later) are flagged stale.
		$stale = 0;
		$by_key = array();
		foreach ( $c['rows'] as $r ) {
			$by_key[ self::candidate_key( $r ) ] = $r;
			if ( $r['wp_user_id'] ) {
				$by_key[ 'user:' . $r['wp_user_id'] ] = $r;
			}
		}
		foreach ( Db::rows( 'SELECT * FROM ' . Db::t( 'program_candidates' ) . " WHERE status = 'drafted' AND case_id IS NOT NULL" ) as $pc ) {
			$current = $by_key[ $pc['candidate_key'] ] ?? ( $pc['wp_user_id'] ? ( $by_key[ 'user:' . $pc['wp_user_id'] ] ?? null ) : null );
			if ( $current && $current['effective_deadline'] && $current['effective_deadline'] !== $pc['deadline'] ) {
				Exceptions::open( 'deadline_moved:' . $pc['id'], 'account_opened_after_charge', 'medium', 'המועד לפתיחת חשבון השתנה בדשבורד אחרי שנוצרה טיוטת חוב', array( 'entity_type' => 'case', 'entity_id' => (int) $pc['case_id'] ) );
				++$stale;
			}
		}

		// Contact details are not in the dashboard; fetch a bounded number per run (the API is slow).
		$enriched = 0;
		foreach ( array_slice( $new_ids, 0, 25 ) as $id ) {
			$enriched += self::enrich( $id ) ? 1 : 0;
		}
		return array( 'source' => $c['source'], 'rows' => count( $c['rows'] ), 'stale' => false, 'new_candidates' => $new, 'enriched' => $enriched, 'opened_after_charge' => $opened_after, 'paid_elsewhere' => $paid_elsewhere, 'stale_drafts' => $stale );
	}

	/**
	 * Adds phone / email / first name from Pipedrive to a candidate keyed by person.
	 * Failure leaves the row as it was (and is retried when a draft is created);
	 * it is never written as empty contact details.
	 */
	public static function enrich( int $candidate_id ): bool {
		$pc = Db::row( 'SELECT * FROM ' . Db::t( 'program_candidates' ) . ' WHERE id = %d', $candidate_id );
		if ( ! $pc || ! $pc['pipedrive_person_id'] ) {
			return false;
		}
		$snap = (array) json_decode( (string) $pc['snapshot'], true );
		if ( ! empty( $snap['enriched_at'] ) ) {
			return true;
		}
		$p = Pipedrive::person( (int) $pc['pipedrive_person_id'] );
		if ( ! $p['ok'] ) {
			$snap['enrich_error'] = $p['error'];
			Db::update( 'program_candidates', array( 'snapshot' => wp_json_encode( $snap, JSON_UNESCAPED_UNICODE ), 'updated_at' => Clock::utc() ), array( 'id' => $candidate_id ) );
			return false;
		}
		$snap['name']        = $p['name'] ?: ( $snap['name'] ?? '' );
		$snap['first_name']  = $p['first_name'];
		$snap['phone']       = $p['phone'];
		$snap['email']       = $p['email'];
		$snap['enriched_at'] = Clock::utc();
		unset( $snap['enrich_error'] );
		$user = $p['email'] ? get_user_by( 'email', $p['email'] ) : false;
		Db::update(
			'program_candidates',
			array(
				'snapshot'   => wp_json_encode( $snap, JSON_UNESCAPED_UNICODE ),
				'wp_user_id' => $pc['wp_user_id'] ?: ( $user ? (int) $user->ID : null ),
				'updated_at' => Clock::utc(),
			),
			array( 'id' => $candidate_id )
		);
		return true;
	}

	/**
	 * The dashboard counts revenue and closes the 90-day commitment only from a won
	 * Pipedrive deal with the penalty product. A charge collected here is invisible to
	 * it until someone records it there, so a fully paid non-open case opens a task on
	 * the enrollment deal. Not automated on purpose: a rep who also records it by hand
	 * would add a second product line, and the dashboard would count the money twice.
	 */
	public static function on_transition( int $case_id, string $from, string $to ): void {
		if ( 'closed' !== $to ) {
			return;
		}
		$row = Db::row( 'SELECT c.customer_id, c.source_type, a.pipedrive_deal_id FROM ' . Db::t( 'cases' ) . ' c LEFT JOIN ' . Db::t( 'agreements' ) . ' a ON a.id = c.agreement_id WHERE c.id = %d', $case_id );
		if ( ! $row || 'non_open_charge' !== $row['source_type'] ) {
			return;
		}
		$paid = (int) Db::value( 'SELECT COALESCE(SUM(a.amount_minor),0) FROM ' . Db::t( 'allocations' ) . ' a JOIN ' . Db::t( 'debt_items' ) . ' d ON d.id = a.debt_item_id WHERE d.case_id = %d', $case_id );
		if ( $paid <= 0 ) {
			return; // closed without money from us (write-off, opened account): nothing to record
		}
		$deal = $row['pipedrive_deal_id'] ? ' #' . (int) $row['pipedrive_deal_id'] : '';
		Tasks::open(
			'crm_record:' . $case_id,
			'crm_record',
			array(
				'case_id'     => $case_id,
				'customer_id' => (int) $row['customer_id'],
				'reason'      => 'התלמיד שילם ' . Money::format( $paid ) . ' ₪ דרך מערכת התשלומים. יש להוסיף לדיל' . $deal . ' בפייפדרייב את מוצר חיוב אי־הפתיחה בסכום הזה ולסמן אותו כ־won, כדי שדשבורד ההכנסות יספור את ההכנסה ויסגור את ההתחייבות.',
			)
		);
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
		$ifd     = FinanceDashboard::contract();
		$penalty = FinanceDashboard::penalty_gross();
		return array(
			'source_in_use' => has_filter( 'icol_beginner_program_candidates' ) ? 'filter' : ( $ifd['available'] ? 'finance_dashboard' : ( '' !== (string) Settings::get( 'revenue_meta_deadline' ) ? 'meta' : 'none' ) ),
			'finance_dashboard' => array_merge(
				$ifd,
				array(
					'counts'        => FinanceDashboard::counts(),
					'penalty_gross' => null === $penalty ? 'לא הוגדר מוצר קנס או שהמחיר טרם נמשך' : Money::format( $penalty ),
				)
			),
			'active_plugins' => $plugins, 'usermeta_candidates' => $keys, 'functions' => $functions, 'rest_routes' => $routes, 'contract' => $filters, 'mapped' => array( 'deadline' => Settings::get( 'revenue_meta_deadline' ), 'opened' => Settings::get( 'revenue_meta_opened' ), 'joined' => Settings::get( 'revenue_meta_joined' ), 'extension' => Settings::get( 'revenue_meta_extension' ) ) );
	}
}
