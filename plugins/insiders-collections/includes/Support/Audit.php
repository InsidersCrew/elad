<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/** Append-only audit trail. Financial rows are never edited to fix a mistake; corrections are new rows. */
final class Audit {
	private static ?string $correlation = null;

	public static function correlation(): string {
		if ( null === self::$correlation ) {
			self::$correlation = Ids::uuid();
		}
		return self::$correlation;
	}

	public static function reset_correlation( ?string $id = null ): void {
		self::$correlation = $id;
	}

	public static function actor(): array {
		$uid = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( $uid > 0 ) {
			return array( 'user', $uid );
		}
		return array( 'system', 0 );
	}

	public static function log( string $action, string $entity_type, int $entity_id, $before = null, $after = null, string $reason = '' ): void {
		[ $type, $id ] = self::actor();
		Db::insert(
			'audit_log',
			array(
				'actor_type'     => $type,
				'actor_id'       => $id,
				'action'         => $action,
				'entity_type'    => $entity_type,
				'entity_id'      => $entity_id,
				'before_json'    => null === $before ? null : wp_json_encode( $before ),
				'after_json'     => null === $after ? null : wp_json_encode( $after ),
				'reason'         => mb_substr( $reason, 0, 1000 ),
				'occurred_at'    => Clock::utc(),
				'correlation_id' => self::correlation(),
			)
		);
	}
}
