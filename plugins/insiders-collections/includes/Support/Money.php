<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Amounts are integer minor units (agorot) with an explicit currency. No floats
 * anywhere in the money path; parsing rejects anything ambiguous instead of guessing.
 */
final class Money {

	public const CURRENCIES = array( 'ILS', 'USD', 'EUR' );

	/** Parse "1,234.50" / "1234.5" / "1234" into minor units. Returns null if invalid. */
	public static function parse( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value * 100;
		}
		$s = trim( (string) $value );
		$s = str_replace( array( '₪', ' ', "\u{00a0}" ), '', $s );
		$s = str_replace( ',', '', $s );
		if ( ! preg_match( '/^-?\d{1,9}(\.\d{1,2})?$/', $s ) ) {
			return null;
		}
		$neg = str_starts_with( $s, '-' );
		$s   = ltrim( $s, '-' );
		$parts = explode( '.', $s );
		$major = (int) $parts[0];
		$minor = isset( $parts[1] ) ? (int) str_pad( $parts[1], 2, '0' ) : 0;
		$total = $major * 100 + $minor;
		return $neg ? -$total : $total;
	}

	/** Provider amounts arrive as decimal strings ("98.00"); same rules as parse(). */
	public static function from_provider( $value ): ?int {
		return self::parse( is_numeric( $value ) ? number_format( (float) $value, 2, '.', '' ) : $value );
	}

	/** "1,234.50" without symbol; whole amounts drop the decimals ("980"). */
	public static function format( int $minor, bool $force_decimals = false ): string {
		$neg   = $minor < 0;
		$abs   = abs( $minor );
		$major = intdiv( $abs, 100 );
		$cents = $abs % 100;
		$out   = number_format( $major, 0, '.', ',' );
		if ( $cents || $force_decimals ) {
			$out .= '.' . str_pad( (string) $cents, 2, '0', STR_PAD_LEFT );
		}
		return ( $neg ? '-' : '' ) . $out;
	}

	public static function symbol( string $currency ): string {
		return array( 'ILS' => '₪', 'USD' => '$', 'EUR' => '€' )[ $currency ] ?? $currency;
	}

	public static function normalize_currency( $value ): ?string {
		$v = strtoupper( trim( (string) $value ) );
		// Tranzila documents numeric currency codes (1 = ILS, 2 = USD, 978 = EUR); keep both readings.
		$map = array( '1' => 'ILS', '376' => 'ILS', 'NIS' => 'ILS', 'ILS' => 'ILS', '2' => 'USD', '840' => 'USD', 'USD' => 'USD', '978' => 'EUR', 'EUR' => 'EUR' );
		return $map[ $v ] ?? null;
	}
}
