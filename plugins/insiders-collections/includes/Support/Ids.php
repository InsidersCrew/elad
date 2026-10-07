<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

final class Ids {
	public static function uuid(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	}

	/** URL-safe random token, 43 chars for 32 bytes. Used for pay links and webhook paths. */
	public static function token( int $bytes = 32 ): string {
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}

	public static function hash( string $value ): string {
		return hash( 'sha256', $value );
	}
}
