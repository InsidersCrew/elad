<?php
namespace Insiders\Collections\Engine;

use Insiders\Collections\Domain\Cases;
use Insiders\Collections\Domain\Exceptions;
use Insiders\Collections\Domain\Ledger;
use Insiders\Collections\Domain\Messaging;
use Insiders\Collections\Domain\Promises;
use Insiders\Collections\Domain\Tasks;
use Insiders\Collections\Domain\Workflow;
use Insiders\Collections\Domain\Calendar;
use Insiders\Collections\Domain\Policy;
use Insiders\Collections\Integrations\RevenueDashboard\Adapter as Revenue;
use Insiders\Collections\Integrations\Tranzila\Client as TranzilaClient;
use Insiders\Collections\Integrations\Tranzila\Reconciler;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Ids;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One tick = inbox → due actions → outbox → reconciliation → daily jobs.
 *
 * Triggered by a server cron (preferred), WP-CLI, or WP-Cron as a fallback.
 * WP-Cron alone is not enough: measured on this site it fired once per 51 minutes.
 * Every job records two timestamps — attempt and success — because "started and
 * died" and "never ran" look identical otherwise.
 *
 * The lock stores its own timestamp: `finally` does not run on a fatal error,
 * and a lock released only there would become permanent.
 */
final class Runner {
	private const LOCK  = 'icol_runner_lock';
	private const BEATS = 'icol_heartbeats';
	private static ?string $token = null;

	public static function tick( string $trigger ): array {
		if ( ! self::lock() ) {
			return array( 'skipped' => 'locked' );
		}
		Audit::reset_correlation( Ids::uuid() );
		$r = array( 'trigger' => $trigger );
		try {
			self::beat( 'tick', 'attempt', $trigger );
			$r['inbox']   = self::job( 'inbox', static fn() => Inbox::process( 50 ) );
			$r['actions'] = self::job( 'actions', static fn() => self::run_actions( 50 ) );
			$r['outbox']  = self::job( 'outbox', static fn() => Outbox::process( 50 ) );
			if ( \Insiders\Collections\Domain\Journey::enabled() && self::due( 'journey', HOUR_IN_SECONDS ) ) {
				$r['journey'] = self::job( 'journey', static fn() => \Insiders\Collections\Domain\Journey::sync() );
			}
			if ( TranzilaClient::configured() && self::due( 'reconcile', 15 * MINUTE_IN_SECONDS ) ) {
				$r['reconcile'] = self::job( 'reconcile', static fn() => Reconciler::incremental() );
			}
			if ( self::daily_due() ) {
				$r['daily'] = self::job( 'daily', static fn() => self::daily() );
			}
			self::health();
			self::beat( 'tick', 'success', $trigger );
		} finally {
			self::unlock();
		}
		return $r;
	}

	private static function lock(): bool {
		$token = Ids::uuid();
		if ( add_option( self::LOCK, wp_json_encode( array( 't' => $token, 'ts' => time() ) ), '', 'no' ) ) {
			self::$token = $token;
			return true;
		}
		$cur = json_decode( (string) get_option( self::LOCK ), true );
		if ( ! is_array( $cur ) || ( time() - (int) ( $cur['ts'] ?? 0 ) ) > 600 ) {
			delete_option( self::LOCK );
			if ( add_option( self::LOCK, wp_json_encode( array( 't' => $token, 'ts' => time() ) ), '', 'no' ) ) {
				self::$token = $token;
				return true;
			}
		}
		return false;
	}

	private static function unlock(): void {
		$cur = json_decode( (string) get_option( self::LOCK ), true );
		if ( is_array( $cur ) && ( $cur['t'] ?? '' ) === self::$token ) {
			delete_option( self::LOCK );
		}
		self::$token = null;
	}

	private static function job( string $name, callable $fn ) {
		self::beat( $name, 'attempt' );
		try {
			$res = $fn();
			self::beat( $name, 'success', is_scalar( $res ) ? (string) $res : wp_json_encode( $res ) );
			return $res;
		} catch ( \Throwable $e ) {
			self::beat( $name, 'error', $e->getMessage() );
			return array( 'error' => $e->getMessage() );
		}
	}

	public static function beat( string $job, string $kind, string $note = '' ): void {
		$beats = (array) get_option( self::BEATS, array() );
		$b     = $beats[ $job ] ?? array();
		$b[ $kind ] = Clock::utc();
		if ( '' !== $note ) {
			$b[ 'error' === $kind ? 'last_error' : 'last_note' ] = mb_substr( $note, 0, 300 );
		}
		$beats[ $job ] = $b;
		update_option( self::BEATS, $beats, false );
	}

	public static function beats(): array {
		return (array) get_option( self::BEATS, array() );
	}

	private static function due( string $job, int $every ): bool {
		$last = Clock::ts( self::beats()[ $job ]['attempt'] ?? null );
		return null === $last || Clock::now() - $last >= $every;
	}

	private static function daily_due(): bool {
		$last = self::beats()['daily']['success'] ?? null;
		return null === $last || Clock::local_date( (int) Clock::ts( $last ) ) !== Clock::today();
	}

	public static function run_actions( int $limit ): array {
		$out = array();
		foreach ( Scheduler::claim_due( $limit ) as $a ) {
			try {
				$res = self::run_action( $a );
			} catch ( \Throwable $e ) {
				Scheduler::retry( (int) $a['id'], Clock::now() + 300, 'error: ' . $e->getMessage() );
				$res = 'error';
			}
			$key         = strtok( $res, ':' );
			$out[ $key ] = ( $out[ $key ] ?? 0 ) + 1;
		}
		return $out;
	}

	private static function run_action( array $a ): string {
		$id = (int) $a['id'];
		switch ( $a['type'] ) {
			case 'send_reminder':
				if ( ( Clock::ts( $a['run_at'] ) ?? 0 ) < Clock::now() - 12 * HOUR_IN_SECONDS ) {
					// §20: after an outage, re-evaluate instead of flushing a backlog of old messages.
					Scheduler::finish( $id, 'cancelled', 'replanned_after_delay' );
					$case = Workflow::get( (int) $a['case_id'] );
					if ( $case && in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
						Messaging::plan_next( (int) $a['case_id'] );
					}
					return 'replanned';
				}
				$res = Messaging::execute_reminder( $a );
				break;
			case 'send_journey':
				if ( ( Clock::ts( $a['run_at'] ) ?? 0 ) < Clock::now() - 12 * HOUR_IN_SECONDS ) {
					Scheduler::finish( $id, 'cancelled', 'replanned_after_delay' );
					\Insiders\Collections\Domain\Journey::plan_next( (int) $a['case_id'] );
					return 'replanned';
				}
				$res = \Insiders\Collections\Domain\Journey::execute( $a );
				if ( 'cancel:stale_step' === $res ) {
					Scheduler::finish( $id, 'cancelled', $res );
					\Insiders\Collections\Domain\Journey::plan_next( (int) $a['case_id'] );
					return $res;
				}
				break;
			case 'escalate_no_reply':
				$res = self::escalate( $a );
				break;
			case 'check_promise':
				$res = Promises::execute_check( $a );
				break;
			default:
				$res = 'cancel:unknown_type';
		}
		if ( str_starts_with( $res, 'retry:' ) ) {
			Scheduler::retry( $id, self::retry_at( $a, $res ), $res );
		} elseif ( str_starts_with( $res, 'cancel:' ) ) {
			Scheduler::finish( $id, 'cancelled', $res );
		} else {
			Scheduler::finish( $id, 'done', $res );
		}
		return $res;
	}

	/** Next attempt for a transient block: the earliest moment the block can lift. */
	private static function retry_at( array $a, string $res ): int {
		$policy = Policy::effective_or_draft();
		$now    = Clock::now();
		if ( str_contains( $res, 'events_pending' ) ) {
			return $now + 5 * MINUTE_IN_SECONDS;
		}
		if ( str_contains( $res, 'quota' ) || str_contains( $res, 'min_gap' ) ) {
			$next = Calendar::add_business_days( Clock::today(), 1, $policy );
			return Calendar::next_slot( Clock::local_to_ts( $next, $policy['window_start'] ) + random_int( 0, 40 ) * MINUTE_IN_SECONDS, $policy );
		}
		return Calendar::next_slot( $now + MINUTE_IN_SECONDS, $policy );
	}

	private static function escalate( array $a ): string {
		$case = Workflow::get( (int) $a['case_id'] );
		if ( ! $case || 'waiting_reply' !== $case['workflow_state'] ) {
			return 'cancel:state_changed';
		}
		Workflow::transition( (int) $case['id'], 'human_review', 'שלוש פניות ללא תשובה, העברה לנציג', null, array(), 'escalation' );
		Tasks::open( 'no_reply:' . $case['id'], 'no_reply_call', array( 'case_id' => (int) $case['id'], 'customer_id' => (int) $case['customer_id'], 'reason' => 'רצף תזכורות הסתיים ללא תשובה' ) );
		return 'escalated';
	}

	public static function daily(): array {
		$r = array();
		$r['due_refreshed'] = Ledger::refresh_due_states();
		// Cases whose items just became due and that have nothing planned.
		$idle = Db::rows(
			'SELECT c.id FROM ' . Db::t( 'cases' ) . " c WHERE c.workflow_state IN ('active','waiting_reply') AND NOT EXISTS (SELECT 1 FROM " . Db::t( 'scheduled_actions' ) . " s WHERE s.case_id = c.id AND s.state IN ('pending','claimed'))"
		);
		foreach ( $idle as $c ) {
			Messaging::plan_next( (int) $c['id'] );
		}
		$r['replanned'] = count( $idle );
		$r['balance_mismatches'] = count( Ledger::verify_cache() );
		\Insiders\Collections\Security\Gate::cleanup();
		$overdue = Db::rows( 'SELECT id FROM ' . Db::t( 'card_update_tasks' ) . " WHERE state IN ('open','in_progress') AND due_at < %s", Clock::utc() );
		foreach ( $overdue as $t ) {
			Exceptions::open( 'card_overdue:' . $t['id'], 'card_task_overdue', 'medium', 'משימת עדכון כרטיס עברה את מועד היעד', array( 'entity_type' => 'card_task', 'entity_id' => (int) $t['id'] ) );
		}
		$r['card_overdue'] = count( $overdue );
		$r['revenue']      = Revenue::sync();
		$r['credit']       = \Insiders\Collections\Domain\ProgramCharges::credit_watch();
		if ( TranzilaClient::configured() ) {
			$r['reconcile_full'] = Reconciler::daily_full();
		}
		Db::exec( 'DELETE FROM ' . Db::t( 'idempotency' ) . ' WHERE created_at < %s', Clock::utc( Clock::now() - 7 * DAY_IN_SECONDS ) );
		$policy = Policy::effective_or_draft();
		$last_blocked = $policy['blocked_dates'] ? max( array_keys( $policy['blocked_dates'] ) ) : '';
		if ( $last_blocked < gmdate( 'Y-m-d', Clock::now() + 90 * DAY_IN_SECONDS ) ) {
			Tasks::alert( 'holidays:' . gmdate( 'Y' ), 'לוח החגים במדיניות מסתיים בקרוב', 'יש לעדכן ימי חסימה לשנה הבאה' );
		}
		return $r;
	}

	/** §20: if reconciliation is stale, reminders that depend on Tranzila data stop. */
	public static function reconcile_stale(): bool {
		if ( ! TranzilaClient::configured() ) {
			return true;
		}
		$last = Clock::ts( self::beats()['reconcile']['success'] ?? null );
		return null === $last || Clock::now() - $last > (int) Settings::get( 'reconcile_stale_minutes' ) * MINUTE_IN_SECONDS;
	}

	private static function health(): void {
		if ( TranzilaClient::configured() && self::reconcile_stale() ) {
			Exceptions::open( 'reconcile_stale:' . Clock::today(), 'reconcile_stale', 'high', 'ההתאמה מול טרנזילה לא הצליחה ב־' . (int) Settings::get( 'reconcile_stale_minutes' ) . ' הדקות האחרונות, תזכורות לחיובים חוזרים מושהות' );
		}
	}

	public static function suspend( string $integration, string $reason ): void {
		$s                 = (array) get_option( 'icol_suspended', array() );
		$s[ $integration ] = array( 'reason' => mb_substr( $reason, 0, 300 ), 'at' => Clock::utc() );
		update_option( 'icol_suspended', $s, false );
		Tasks::alert( 'suspended:' . $integration . ':' . Clock::today(), 'חיבור ' . $integration . ' הושעה', $reason );
	}

	public static function unsuspend( string $integration ): void {
		$s = (array) get_option( 'icol_suspended', array() );
		unset( $s[ $integration ] );
		update_option( 'icol_suspended', $s, false );
		Audit::log( 'integration.unsuspend', 'integration', 0, null, array( 'name' => $integration ), '' );
	}

	public static function integration_suspended( string $integration ): bool {
		return isset( ( (array) get_option( 'icol_suspended', array() ) )[ $integration ] );
	}
}
