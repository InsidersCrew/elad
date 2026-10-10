<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What a beginner-program student owes when no account was opened.
 *
 * The date on the agreement decides the price: a student who signed before a
 * price change keeps the old price. The registration fee most students paid at
 * signing is credited against it, unless the deal carries the "no registration
 * fee" label (students enrolled automatically, without the fee).
 *
 * The price table is a setting, one line per price list:
 *   2026-08-01 980 100   (effective from, full price, registration fee) in shekels.
 */
final class Pricing {

	/** Parsed table, oldest first. A broken line is skipped and reported by validate(). */
	private static ?string $cached_for = null;
	private static array $cached = array();

	public static function table(): array {
		$text = (string) Settings::get( 'program_price_table' );
		if ( $text === self::$cached_for ) {
			return self::$cached;
		}
		$rows = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$r = self::parse_line( $line );
			if ( $r ) {
				$rows[ $r['from'] ] = $r;
			}
		}
		ksort( $rows );
		self::$cached_for = $text;
		self::$cached     = array_values( $rows );
		return self::$cached;
	}

	private static function parse_line( string $line ): ?array {
		$parts = preg_split( '/[\s,;]+/', trim( $line ) );
		if ( count( $parts ) < 2 || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $parts[0], $d ) || ! checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) ) {
			return null;
		}
		$total = Money::parse( $parts[1] );
		$fee   = isset( $parts[2] ) ? Money::parse( $parts[2] ) : 0;
		if ( null === $total || $total <= 0 || null === $fee || $fee < 0 || $fee >= $total ) {
			return null;
		}
		return array( 'from' => $parts[0], 'total' => $total, 'registration' => $fee );
	}

	/** Lines that would be ignored, for the settings screen. */
	public static function validate( string $text ): array {
		$bad = array();
		foreach ( preg_split( '/\R/', $text ) as $n => $line ) {
			if ( '' !== trim( $line ) && ! self::parse_line( $line ) ) {
				$bad[] = 'שורה ' . ( $n + 1 ) . ': ' . trim( $line );
			}
		}
		return $bad;
	}

	/** The price list in force on the signing date (the earliest list when the date precedes all of them). */
	public static function row_for( ?string $signed_at ): ?array {
		$table = self::table();
		if ( ! $table ) {
			return null;
		}
		$pick = $table[0];
		foreach ( $table as $r ) {
			if ( null !== $signed_at && $r['from'] <= $signed_at ) {
				$pick = $r;
			}
		}
		return $pick;
	}

	/**
	 * @return array{total:int,credit:int,due:int,text:string,from:string}|null
	 */
	public static function quote( ?string $signed_at, bool $no_registration_fee ): ?array {
		$row = self::row_for( $signed_at );
		if ( ! $row ) {
			return null;
		}
		$credit = $no_registration_fee ? 0 : (int) $row['registration'];
		$due    = (int) $row['total'] - $credit;
		$text   = $credit > 0
			? Money::ils( $due ) . ' (' . Money::ils( (int) $row['total'] ) . ' פחות ' . Money::ils( $credit ) . ' דמי הרישום ששולמו)'
			: Money::ils( $due );
		return array( 'total' => (int) $row['total'], 'credit' => $credit, 'due' => $due, 'text' => $text, 'from' => $row['from'] );
	}

	/** Quote for an agreement row, from its signing date and the label read from the deal. */
	public static function for_agreement( array $agr ): ?array {
		return self::quote( $agr['signed_at'] ?: ( $agr['joined_at'] ?: null ), ! empty( $agr['no_registration_fee'] ) );
	}

	/** The message variable: "880 ₪ (980 ₪ פחות 100 ₪ דמי הרישום ששולמו)". */
	public static function amount_text( array $agr ): string {
		$q = self::for_agreement( $agr );
		return $q ? $q['text'] : '';
	}
}
