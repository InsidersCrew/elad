<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/** Israeli-first phone normalization to E.164. A phone is never an identity on its own. */
final class Phone {

	public static function e164( $raw ): ?string {
		$digits = preg_replace( '/\D+/', '', (string) $raw );
		if ( '' === $digits ) {
			return null;
		}
		if ( str_starts_with( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( str_starts_with( $digits, '972' ) ) {
			$national = ltrim( substr( $digits, 3 ), '0' );
		} elseif ( str_starts_with( $digits, '0' ) ) {
			$national = substr( $digits, 1 );
		} elseif ( strlen( $digits ) === 9 && str_starts_with( $digits, '5' ) ) {
			$national = $digits;
		} else {
			// Foreign number: accept as-is if plausible length.
			return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? '+' . $digits : null;
		}
		if ( strlen( $national ) < 8 || strlen( $national ) > 9 ) {
			return null;
		}
		return '+972' . $national;
	}

	/** WATI identifies contacts by waId = digits without '+'. */
	public static function wa_id( string $e164 ): string {
		return ltrim( $e164, '+' );
	}

	public static function mask( ?string $e164 ): string {
		if ( ! $e164 ) {
			return '';
		}
		return substr( $e164, 0, 6 ) . '•••' . substr( $e164, -3 );
	}
}
