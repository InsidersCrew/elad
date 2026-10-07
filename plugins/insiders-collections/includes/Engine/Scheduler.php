<?php
namespace Insiders\Collections\Engine;

use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Ids;

defined( 'ABSPATH' ) || exit;

/**
 * Durable scheduled actions (§20 מתזמן). Every action has a run time, a unique
 * idempotency key, the policy version it was planned under and a claim lease.
 * Nothing lives only in process memory.
 */
final class Scheduler {
	public const LEASE = 300;

	public static function schedule( string $type, int $run_at, string $key, ?int $case_id, ?int $customer_id, array $payload, ?int $policy_version, bool $revive = true ): int {
		$existing = Db::row( 'SELECT id, state FROM ' . Db::t( 'scheduled_actions' ) . ' WHERE idempotency_key = %s', $key );
		if ( $existing ) {
			if ( 'cancelled' === $existing['state'] && $revive ) {
				Db::update( 'scheduled_actions', array( 'state' => 'pending', 'run_at' => Clock::utc( $run_at ), 'payload' => wp_json_encode( $payload ), 'policy_version' => $policy_version, 'claimed_by' => null, 'claimed_until' => null, 'result' => null, 'created_at' => Clock::utc(), 'updated_at' => Clock::utc() ), array( 'id' => (int) $existing['id'] ) );
			} elseif ( 'pending' === $existing['state'] ) {
				Db::update( 'scheduled_actions', array( 'run_at' => Clock::utc( $run_at ), 'updated_at' => Clock::utc() ), array( 'id' => (int) $existing['id'] ) );
			}
			return (int) $existing['id'];
		}
		try {
			return Db::insert(
				'scheduled_actions',
				array(
					'case_id'         => $case_id,
					'customer_id'     => $customer_id,
					'type'            => $type,
					'run_at'          => Clock::utc( $run_at ),
					'state'           => 'pending',
					'policy_version'  => $policy_version,
					'idempotency_key' => $key,
					'payload'         => wp_json_encode( $payload ),
					'created_at'      => Clock::utc(),
					'updated_at'      => Clock::utc(),
				)
			);
		} catch ( \Insiders\Collections\Support\DbError $e ) {
			if ( $e->duplicate ) {
				return (int) Db::value( 'SELECT id FROM ' . Db::t( 'scheduled_actions' ) . ' WHERE idempotency_key = %s', $key );
			}
			throw $e;
		}
	}

	public static function cancel_for_case( int $case_id, string $reason ): int {
		return Db::exec(
			'UPDATE ' . Db::t( 'scheduled_actions' ) . " SET state = 'cancelled', result = %s, updated_at = %s WHERE case_id = %d AND state = 'pending' AND type IN ('send_reminder','escalate_no_reply','check_promise')",
			mb_substr( 'cancelled:' . $reason, 0, 500 ),
			Clock::utc(),
			$case_id
		);
	}

	/** Claim due actions with a lease. A crashed worker's lease expires and the action is re-judged. */
	public static function claim_due( int $limit ): array {
		$now   = Clock::utc();
		$rows  = Db::rows(
			'SELECT id FROM ' . Db::t( 'scheduled_actions' ) . " WHERE (state = 'pending' AND run_at <= %s) OR (state = 'claimed' AND claimed_until < %s) ORDER BY run_at LIMIT %d",
			$now,
			$now,
			$limit
		);
		$token   = substr( Ids::uuid(), 0, 36 );
		$claimed = array();
		foreach ( $rows as $r ) {
			$n = Db::exec(
				'UPDATE ' . Db::t( 'scheduled_actions' ) . " SET state = 'claimed', claimed_by = %s, claimed_until = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND ((state = 'pending' AND run_at <= %s) OR (state = 'claimed' AND claimed_until < %s))",
				$token,
				Clock::utc( Clock::now() + self::LEASE ),
				$now,
				(int) $r['id'],
				$now,
				$now
			);
			if ( 1 === $n ) {
				$claimed[] = Db::row( 'SELECT * FROM ' . Db::t( 'scheduled_actions' ) . ' WHERE id = %d', (int) $r['id'] );
			}
		}
		return $claimed;
	}

	public static function finish( int $id, string $state, string $result ): void {
		Db::update( 'scheduled_actions', array( 'state' => $state, 'result' => mb_substr( $result, 0, 500 ), 'claimed_until' => null, 'updated_at' => Clock::utc() ), array( 'id' => $id ) );
	}

	public static function retry( int $id, int $run_at, string $reason ): void {
		$row = Db::row( 'SELECT attempts FROM ' . Db::t( 'scheduled_actions' ) . ' WHERE id = %d', $id );
		if ( $row && (int) $row['attempts'] >= 25 ) {
			self::finish( $id, 'blocked', 'too_many_retries:' . $reason );
			return;
		}
		Db::update( 'scheduled_actions', array( 'state' => 'pending', 'run_at' => Clock::utc( $run_at ), 'last_error' => mb_substr( $reason, 0, 500 ), 'claimed_by' => null, 'claimed_until' => null, 'updated_at' => Clock::utc() ), array( 'id' => $id ) );
	}
}
