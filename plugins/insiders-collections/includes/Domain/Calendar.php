<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Israeli business calendar for outreach timing.
 *
 * Holidays are generated from the Hebrew calendar (ext-calendar) and written into
 * the policy as an editable block list — the generator proposes, the admin decides.
 * Memorial days are blocked by default: a collection reminder on Yom HaZikaron is
 * not a timing detail.
 */
final class Calendar {

	/** @return array{blocked: array<string,string>, half: array<string,string>} date => label */
	public static function israeli_holidays( int $from_year, int $to_year ): array {
		$blocked = array();
		$half    = array();
		if ( ! function_exists( 'jewishtojd' ) ) {
			return array( 'blocked' => $blocked, 'half' => $half );
		}
		for ( $h = $from_year + 3760; $h <= $to_year + 3761; $h++ ) {
			$add = static function ( array &$bucket, int $month, int $day, int $year, string $label ) {
				$jd = jewishtojd( $month, $day, $year );
				if ( $jd > 0 ) {
					$bucket[ self::jd_to_date( $jd ) ] = $label;
				}
			};
			// Tishrei = 1, Nisan = 8, Iyar = 9, Sivan = 10, Av = 12, Elul = 13 (PHP numbering).
			$add( $half, 13, 29, $h - 1, 'ערב ראש השנה' );
			$add( $blocked, 1, 1, $h, 'ראש השנה' );
			$add( $blocked, 1, 2, $h, 'ראש השנה' );
			$add( $half, 1, 9, $h, 'ערב יום כיפור' );
			$add( $blocked, 1, 10, $h, 'יום כיפור' );
			$add( $half, 1, 14, $h, 'ערב סוכות' );
			$add( $blocked, 1, 15, $h, 'סוכות' );
			$add( $half, 1, 21, $h, 'הושענא רבה' );
			$add( $blocked, 1, 22, $h, 'שמחת תורה' );
			$add( $half, 8, 14, $h, 'ערב פסח' );
			$add( $blocked, 8, 15, $h, 'פסח' );
			$add( $half, 8, 20, $h, 'ערב שביעי של פסח' );
			$add( $blocked, 8, 21, $h, 'שביעי של פסח' );
			$add( $half, 10, 5, $h, 'ערב שבועות' );
			$add( $blocked, 10, 6, $h, 'שבועות' );

			// Yom HaShoah: 27 Nisan; Friday -> Thursday, Sunday -> Monday.
			$jd  = jewishtojd( 8, 27, $h );
			$dow = jddayofweek( $jd );
			if ( 5 === $dow ) {
				--$jd;
			} elseif ( 0 === $dow ) {
				++$jd;
			}
			$blocked[ self::jd_to_date( $jd ) ] = 'יום השואה';

			// Yom HaAtzmaut: 5 Iyar; Fri/Sat -> preceding Thursday, Monday -> Tuesday. Zikaron is the day before.
			$jd  = jewishtojd( 9, 5, $h );
			$dow = jddayofweek( $jd );
			if ( 5 === $dow ) {
				--$jd;
			} elseif ( 6 === $dow ) {
				$jd -= 2;
			} elseif ( 1 === $dow ) {
				++$jd;
			}
			$blocked[ self::jd_to_date( $jd - 1 ) ] = 'יום הזיכרון';
			$blocked[ self::jd_to_date( $jd ) ]     = 'יום העצמאות';

			// Tisha B'Av: 9 Av, deferred to 10 Av when it falls on Shabbat.
			$jd = jewishtojd( 12, 9, $h );
			if ( 6 === jddayofweek( $jd ) ) {
				++$jd;
			}
			$blocked[ self::jd_to_date( $jd ) ] = 'תשעה באב';
		}
		$in_range = static function ( $d ) use ( $from_year, $to_year ) {
			$y = (int) substr( $d, 0, 4 );
			return $y >= $from_year && $y <= $to_year;
		};
		$blocked = array_filter( $blocked, $in_range, ARRAY_FILTER_USE_KEY );
		$half    = array_diff_key( array_filter( $half, $in_range, ARRAY_FILTER_USE_KEY ), $blocked );
		ksort( $blocked );
		ksort( $half );
		return array( 'blocked' => $blocked, 'half' => $half );
	}

	private static function jd_to_date( int $jd ): string {
		[ $m, $d, $y ] = array_map( 'intval', explode( '/', jdtogregorian( $jd ) ) );
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	public static function is_business_day( string $date, array $policy ): bool {
		$dow = (int) ( new \DateTimeImmutable( $date, Clock::tz() ) )->format( 'w' );
		if ( ! in_array( $dow, array_map( 'intval', $policy['send_days'] ), true ) ) {
			return false;
		}
		return ! isset( $policy['blocked_dates'][ $date ] );
	}

	public static function add_business_days( string $date, int $n, array $policy ): string {
		$d    = new \DateTimeImmutable( $date, Clock::tz() );
		$step = $n >= 0 ? '+1 day' : '-1 day';
		$left = abs( $n );
		$guard = 0;
		while ( $left > 0 && $guard++ < 400 ) {
			$d = $d->modify( $step );
			if ( self::is_business_day( $d->format( 'Y-m-d' ), $policy ) ) {
				--$left;
			}
		}
		return $d->format( 'Y-m-d' );
	}

	/** Window [start, end) for a local date, honoring half-day cutoffs. Null if not a business day. */
	public static function window( string $date, array $policy ): ?array {
		if ( ! self::is_business_day( $date, $policy ) ) {
			return null;
		}
		$end = $policy['window_end'];
		if ( isset( $policy['half_days'][ $date ] ) && $policy['erev_cutoff'] < $end ) {
			$end = $policy['erev_cutoff'];
		}
		$start_ts = Clock::local_to_ts( $date, $policy['window_start'] );
		$end_ts   = Clock::local_to_ts( $date, $end );
		return $end_ts > $start_ts ? array( $start_ts, $end_ts ) : null;
	}

	public static function in_window( int $ts, array $policy ): bool {
		$w = self::window( Clock::local_date( $ts ), $policy );
		return null !== $w && $ts >= $w[0] && $ts < $w[1];
	}

	/** Earliest moment >= $ts that is inside an allowed window. */
	public static function next_slot( int $ts, array $policy ): int {
		$date  = Clock::local_date( $ts );
		$guard = 0;
		while ( $guard++ < 60 ) {
			$w = self::window( $date, $policy );
			if ( null !== $w ) {
				if ( $ts < $w[0] ) {
					return $w[0];
				}
				if ( $ts < $w[1] ) {
					return $ts;
				}
			}
			$date = ( new \DateTimeImmutable( $date, Clock::tz() ) )->modify( '+1 day' )->format( 'Y-m-d' );
			$ts   = Clock::local_to_ts( $date, '00:00' );
		}
		return $ts;
	}

	/** Business days between two local dates (exclusive start, inclusive end). */
	public static function business_days_between( string $from, string $to, array $policy ): int {
		if ( $to <= $from ) {
			return 0;
		}
		$n = 0;
		$d = new \DateTimeImmutable( $from, Clock::tz() );
		$guard = 0;
		while ( $d->format( 'Y-m-d' ) < $to && $guard++ < 800 ) {
			$d = $d->modify( '+1 day' );
			if ( self::is_business_day( $d->format( 'Y-m-d' ), $policy ) ) {
				++$n;
			}
		}
		return $n;
	}
}
