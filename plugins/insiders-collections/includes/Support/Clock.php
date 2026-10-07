<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Time rules (paid for in SPD): store UTC, parse stored values as UTC, display in
 * the business timezone. current_time('mysql') is site-local and strtotime() reads
 * it as UTC — that offset once made a staleness check fire right after success.
 *
 * Tests freeze time through Clock::freeze().
 */
final class Clock {
	private static ?int $frozen = null;

	public static function freeze( ?int $ts ): void {
		self::$frozen = $ts;
	}

	public static function now(): int {
		return self::$frozen ?? time();
	}

	public static function tz(): \DateTimeZone {
		$name = Settings::get( 'business_timezone', 'Asia/Jerusalem' );
		try {
			return new \DateTimeZone( $name );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'Asia/Jerusalem' );
		}
	}

	/** UTC 'Y-m-d H:i:s' for storage. */
	public static function utc( ?int $ts = null ): string {
		return gmdate( 'Y-m-d H:i:s', $ts ?? self::now() );
	}

	/** Parse a stored UTC value. Returns null for empty. */
	public static function ts( ?string $utc ): ?int {
		if ( null === $utc || '' === $utc || '0000-00-00 00:00:00' === $utc ) {
			return null;
		}
		$t = strtotime( $utc . ' UTC' );
		return false === $t ? null : $t;
	}

	/** Local business date (Y-m-d) for a timestamp. */
	public static function local_date( ?int $ts = null ): string {
		return self::local( $ts )->format( 'Y-m-d' );
	}

	public static function local( ?int $ts = null ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( '@' . ( $ts ?? self::now() ) ) )->setTimezone( self::tz() );
	}

	/** Timestamp for a local date + time string in the business timezone. */
	public static function local_to_ts( string $date, string $time = '00:00' ): int {
		$dt = new \DateTimeImmutable( $date . ' ' . $time, self::tz() );
		return $dt->getTimestamp();
	}

	public static function display( ?string $utc, string $format = 'd/m/Y H:i' ): string {
		$ts = self::ts( $utc );
		return null === $ts ? '' : self::local( $ts )->format( $format );
	}

	public static function today(): string {
		return self::local_date();
	}
}
