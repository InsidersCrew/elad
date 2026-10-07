<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Case handling state (§13). Finance state lives on debt items and card state on
 * recurring orders — three separate axes, never one combined status.
 *
 * Every transition is a compare-and-set on the case version, so two reps cannot
 * overwrite each other, and every transition is audited with before/after/reason.
 */
final class Workflow {

	public const STATES = array( 'draft', 'active', 'waiting_reply', 'promise_pending', 'promise_hold', 'payment_verification', 'human_review', 'paused', 'closed' );

	/** States in which automated customer reminders may be sent. */
	public const SENDABLE = array( 'active', 'waiting_reply' );

	private const ALLOWED = array(
		'draft'                => array( 'active', 'closed', 'human_review', 'promise_hold' ),
		'active'               => array( 'active', 'waiting_reply', 'promise_pending', 'promise_hold', 'payment_verification', 'human_review', 'paused', 'closed' ),
		'waiting_reply'        => array( 'waiting_reply', 'active', 'promise_pending', 'promise_hold', 'payment_verification', 'human_review', 'paused', 'closed' ),
		'promise_pending'      => array( 'promise_hold', 'active', 'payment_verification', 'human_review', 'paused', 'closed' ),
		'promise_hold'         => array( 'active', 'payment_verification', 'human_review', 'paused', 'closed', 'promise_hold' ),
		'payment_verification' => array( 'active', 'human_review', 'paused', 'closed', 'promise_hold' ),
		'human_review'         => array( 'active', 'paused', 'closed', 'promise_hold', 'payment_verification', 'human_review' ),
		'paused'               => array( 'active', 'human_review', 'closed', 'paused' ),
		'closed'               => array( 'human_review' ),
	);

	public static function get( int $case_id, bool $lock = false ): ?array {
		return Db::row( 'SELECT * FROM ' . Db::t( 'cases' ) . ' WHERE id = %d' . ( $lock ? ' FOR UPDATE' : '' ), $case_id );
	}

	public static function can( string $from, string $to ): bool {
		return in_array( $to, self::ALLOWED[ $from ] ?? array(), true );
	}

	/**
	 * @param int|null $expected_version Pass the version the UI saw; null for system events
	 *                                   (they re-read under lock instead).
	 */
	public static function transition( int $case_id, string $to, string $reason, ?int $expected_version = null, array $extra = array(), string $source = '' ): array {
		return Db::transaction(
			function () use ( $case_id, $to, $reason, $expected_version, $extra, $source ) {
				$case = self::get( $case_id, true );
				if ( ! $case ) {
					throw new DomainError( 'not_found', 'התיק לא נמצא', 404 );
				}
				if ( null !== $expected_version && (int) $case['version'] !== $expected_version ) {
					throw new DomainError( 'version_conflict', 'התיק עודכן בינתיים על ידי משתמש אחר. יש לרענן.', 409 );
				}
				$from = $case['workflow_state'];
				if ( ! self::can( $from, $to ) ) {
					throw new DomainError( 'invalid_transition', sprintf( 'לא ניתן לעבור מ־%s ל־%s', self::label( $from ), self::label( $to ) ), 422 );
				}
				$data = array_merge(
					$extra,
					array(
						'workflow_state' => $to,
						'state_reason'   => mb_substr( $reason, 0, 255 ),
						'version'        => (int) $case['version'] + 1,
						'updated_at'     => Clock::utc(),
					)
				);
				if ( 'closed' === $to ) {
					$data['closed_at']  = Clock::utc();
					$data['active_key'] = null;
				} elseif ( 'closed' === $from ) {
					$data['closed_at']  = null;
					$data['active_key'] = $case['case_key'];
				}
				$n = Db::exec(
					self::update_sql( $data ) . ' WHERE id = %d AND version = %d',
					...array_merge( self::bind( $data ), array( $case_id, (int) $case['version'] ) )
				);
				if ( 1 !== $n ) {
					throw new DomainError( 'version_conflict', 'התיק עודכן בינתיים. יש לרענן.', 409 );
				}
				if ( ! in_array( $to, self::SENDABLE, true ) || 'active' === $to ) {
					// Leaving the sendable states — or restarting — voids every planned reminder.
					// Resume never "catches up" old messages (§13 חידוש טיפול).
					Scheduler::cancel_for_case( $case_id, 'state:' . $to );
				}
				Audit::log( 'case.transition', 'case', $case_id, array( 'state' => $from, 'version' => (int) $case['version'] ), array( 'state' => $to, 'version' => (int) $case['version'] + 1 ), $reason . ( $source ? ' [' . $source . ']' : '' ) );
				$case = array_merge( $case, $data );
				do_action( 'icol_case_transitioned', $case_id, $from, $to, $reason );
				return $case;
			}
		);
	}

	/** Values for update_sql(): NULLs are inlined as literals, so they are not bound. */
	private static function bind( array $data ): array {
		return array_values( array_filter( $data, static fn( $v ) => null !== $v ) );
	}

	private static function update_sql( array $data ): string {
		$sets = array();
		foreach ( $data as $col => $val ) {
			$sets[] = null === $val ? "`{$col}` = NULL" : "`{$col}` = %s";
		}
		return 'UPDATE ' . Db::t( 'cases' ) . ' SET ' . implode( ', ', $sets );
	}

	/** Bumps version + fields without changing state (owner change, next action). */
	public static function touch( int $case_id, array $data, string $action, string $reason, ?int $expected_version = null ): array {
		return Db::transaction(
			function () use ( $case_id, $data, $action, $reason, $expected_version ) {
				$case = self::get( $case_id, true );
				if ( ! $case ) {
					throw new DomainError( 'not_found', 'התיק לא נמצא', 404 );
				}
				if ( null !== $expected_version && (int) $case['version'] !== $expected_version ) {
					throw new DomainError( 'version_conflict', 'התיק עודכן בינתיים. יש לרענן.', 409 );
				}
				$data['version']    = (int) $case['version'] + 1;
				$data['updated_at'] = Clock::utc();
				Db::exec( self::update_sql( $data ) . ' WHERE id = %d', ...array_merge( self::bind( $data ), array( $case_id ) ) );
				Audit::log( $action, 'case', $case_id, array_intersect_key( $case, $data ), $data, $reason );
				return array_merge( $case, $data );
			}
		);
	}

	public static function label( string $state ): string {
		return array(
			'draft'                => 'טיוטה',
			'active'               => 'פעיל',
			'waiting_reply'        => 'ממתין לתשובה',
			'promise_pending'      => 'בקשת מועד ממתינה',
			'promise_hold'         => 'המתנה להבטחה',
			'payment_verification' => 'אימות תשלום',
			'human_review'         => 'אצל נציג',
			'paused'               => 'מושהה',
			'closed'               => 'סגור',
		)[ $state ] ?? $state;
	}
}
