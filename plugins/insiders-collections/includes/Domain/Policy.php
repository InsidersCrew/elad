<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned outreach policy (§9, §25). Sending is impossible until a version is
 * approved: the defaults are a proposal, not a decision. Every scheduled action
 * records the policy version it was planned under.
 */
final class Policy {

	public static function defaults(): array {
		$y        = (int) gmdate( 'Y', Clock::now() );
		$holidays = Calendar::israeli_holidays( $y, $y + 1 );
		return array(
			'send_days'                 => array( 0, 1, 2, 3, 4 ), // Sunday..Thursday (PHP 'w')
			'window_start'              => '09:00',
			'window_end'                => '18:00',
			'erev_cutoff'               => '13:00',
			'blocked_dates'             => $holidays['blocked'],
			'half_days'                 => $holidays['half'],
			'cadence_business_days'     => array( 3, 4 ),  // reminder 2 after 3 days, reminder 3 after 4 more
			'max_reminders'             => 3,
			'quota_window_business_days' => 7,
			'quota_max'                 => 3,
			'min_gap_hours'             => 48,
			'broken_promise_followups'  => 1,
			'first_contact_jitter_minutes' => 40,
			// Wait for My Billing's own retry before the first message, by failure class.
			// null = never message automatically; route to a person.
			'grace_business_days'       => array(
				'technical'          => 2,
				'insufficient_funds' => 2,
				'declined'           => 1,
				'expired_card'       => 0,
				'card_invalid'       => 0,
				'suspected_fraud'    => null,
				'unknown'            => null,
			),
			'auto_postpone'             => array(
				'enabled'           => false,
				'max_days'          => 7,
				'max_amount_minor'  => 0,
				'max_count'         => 1,
				'allow_if_prior_promises' => false,
			),
		);
	}

	/** Latest approved version in effect, or null (= sending blocked). */
	public static function current(): ?array {
		$row = Db::row(
			'SELECT * FROM ' . Db::t( 'policies' ) . ' WHERE approved_at IS NOT NULL AND (effective_at IS NULL OR effective_at <= %s) ORDER BY version DESC LIMIT 1',
			Clock::utc()
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/** Policy used for planning/preview: approved if any, else latest draft (flagged). */
	public static function effective_or_draft(): array {
		$p = self::current();
		if ( $p ) {
			return $p;
		}
		$row = Db::row( 'SELECT * FROM ' . Db::t( 'policies' ) . ' ORDER BY version DESC LIMIT 1' );
		if ( $row ) {
			return self::hydrate( $row );
		}
		$cfg              = self::defaults();
		$cfg['_version']  = 0;
		$cfg['_approved'] = false;
		return $cfg;
	}

	private static function hydrate( array $row ): array {
		$cfg              = array_replace( self::defaults(), (array) json_decode( $row['config'], true ) );
		$cfg['_version']  = (int) $row['version'];
		$cfg['_approved'] = null !== $row['approved_at'];
		$cfg['_effective_at'] = $row['effective_at'];
		return $cfg;
	}

	public static function seed(): void {
		$exists = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'policies' ) );
		if ( $exists > 0 ) {
			return;
		}
		Db::insert(
			'policies',
			array(
				'version'    => 1,
				'config'     => wp_json_encode( self::defaults() ),
				'note'       => 'ברירות מחדל מוצעות מהאפיון, דורש אישור מנהל לפני הפעלה',
				'created_at' => Clock::utc(),
			)
		);
	}

	/** Creates a new draft version. Approved versions are immutable. */
	public static function save_draft( array $config, string $note ): int {
		$clean   = self::validate( $config );
		$version = (int) Db::value( 'SELECT COALESCE(MAX(version),0) FROM ' . Db::t( 'policies' ) ) + 1;
		Db::insert(
			'policies',
			array(
				'version'    => $version,
				'config'     => wp_json_encode( $clean ),
				'note'       => mb_substr( $note, 0, 500 ),
				'created_by' => get_current_user_id() ?: null,
				'created_at' => Clock::utc(),
			)
		);
		Audit::log( 'policy.draft', 'policy', $version, null, $clean, $note );
		return $version;
	}

	public static function approve( int $version, ?string $effective_at_utc = null ): void {
		$row = Db::row( 'SELECT * FROM ' . Db::t( 'policies' ) . ' WHERE version = %d', $version );
		if ( ! $row ) {
			throw new DomainError( 'not_found', 'גרסת מדיניות לא נמצאה', 404 );
		}
		if ( null !== $row['approved_at'] ) {
			return;
		}
		Db::update(
			'policies',
			array(
				'approved_by'  => get_current_user_id() ?: null,
				'approved_at'  => Clock::utc(),
				'effective_at' => $effective_at_utc ?? Clock::utc(),
			),
			array( 'version' => $version )
		);
		Audit::log( 'policy.approve', 'policy', $version, null, array( 'effective_at' => $effective_at_utc ), '' );
	}

	public static function validate( array $c ): array {
		$d   = self::defaults();
		$out = array_replace( $d, array_intersect_key( $c, $d ) );
		$time = '/^([01]\d|2[0-3]):[0-5]\d$/';
		foreach ( array( 'window_start', 'window_end', 'erev_cutoff' ) as $k ) {
			if ( ! preg_match( $time, (string) $out[ $k ] ) ) {
				throw new DomainError( 'invalid_policy', 'שעה לא תקינה: ' . $k, 400, array( $k => 'פורמט HH:MM' ) );
			}
		}
		if ( $out['window_start'] >= $out['window_end'] ) {
			throw new DomainError( 'invalid_policy', 'שעת סיום חייבת להיות אחרי שעת התחלה', 400, array( 'window_end' => 'מאוחר משעת ההתחלה' ) );
		}
		$out['send_days'] = array_values( array_unique( array_filter( array_map( 'intval', (array) $out['send_days'] ), static fn( $x ) => $x >= 0 && $x <= 6 ) ) );
		foreach ( array( 'blocked_dates', 'half_days' ) as $k ) {
			$clean = array();
			foreach ( (array) $out[ $k ] as $date => $label ) {
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) {
					$clean[ $date ] = mb_substr( (string) $label, 0, 60 );
				}
			}
			ksort( $clean );
			$out[ $k ] = $clean;
		}
		$out['cadence_business_days'] = array_map( static fn( $x ) => max( 1, (int) $x ), (array) $out['cadence_business_days'] );
		$out['max_reminders']         = max( 1, min( 6, (int) $out['max_reminders'] ) );
		$out['quota_max']             = max( 1, min( 6, (int) $out['quota_max'] ) );
		$out['quota_window_business_days'] = max( 1, (int) $out['quota_window_business_days'] );
		$out['min_gap_hours']         = max( 24, (int) $out['min_gap_hours'] );
		return $out;
	}
}
