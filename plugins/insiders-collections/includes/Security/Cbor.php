<?php
namespace Insiders\Collections\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal CBOR decoder (RFC 8949) for WebAuthn: attestation objects and COSE keys.
 * Byte strings and text strings both come back as PHP strings; callers know which
 * key holds which. Indefinite lengths and tags are refused: authenticators do not
 * emit them in these structures, and accepting them only widens what we parse.
 */
final class Cbor {

	/** Decodes one item. $offset is advanced past it, so trailing data can be located. */
	public static function decode( string $data, int &$offset = 0 ) {
		$len = strlen( $data );
		if ( $offset >= $len ) {
			throw new \UnexpectedValueException( 'cbor: unexpected end' );
		}
		$ib    = ord( $data[ $offset++ ] );
		$major = $ib >> 5;
		$info  = $ib & 0x1f;
		if ( 7 === $major ) {
			return self::simple( $info, $data, $offset );
		}
		$arg = self::argument( $info, $data, $offset );
		switch ( $major ) {
			case 0:
				return $arg;
			case 1:
				return -1 - $arg;
			case 2:
			case 3:
				if ( $offset + $arg > $len ) {
					throw new \UnexpectedValueException( 'cbor: string past end' );
				}
				$s       = substr( $data, $offset, $arg );
				$offset += $arg;
				return $s;
			case 4:
				$out = array();
				for ( $i = 0; $i < $arg; $i++ ) {
					$out[] = self::decode( $data, $offset );
				}
				return $out;
			case 5:
				$out = array();
				for ( $i = 0; $i < $arg; $i++ ) {
					$k = self::decode( $data, $offset );
					if ( ! is_int( $k ) && ! is_string( $k ) ) {
						throw new \UnexpectedValueException( 'cbor: unsupported map key' );
					}
					$out[ $k ] = self::decode( $data, $offset );
				}
				return $out;
			default:
				throw new \UnexpectedValueException( 'cbor: tags are not supported' );
		}
	}

	private static function argument( int $info, string $data, int &$offset ): int {
		if ( $info < 24 ) {
			return $info;
		}
		$bytes = array( 24 => 1, 25 => 2, 26 => 4, 27 => 8 )[ $info ] ?? null;
		if ( null === $bytes ) {
			throw new \UnexpectedValueException( 'cbor: indefinite or reserved length' );
		}
		if ( $offset + $bytes > strlen( $data ) ) {
			throw new \UnexpectedValueException( 'cbor: length past end' );
		}
		$v = 0;
		for ( $i = 0; $i < $bytes; $i++ ) {
			$v = ( $v << 8 ) | ord( $data[ $offset++ ] );
		}
		if ( $v < 0 ) {
			throw new \UnexpectedValueException( 'cbor: integer overflow' );
		}
		return $v;
	}

	private static function simple( int $info, string $data, int &$offset ) {
		switch ( $info ) {
			case 20:
				return false;
			case 21:
				return true;
			case 22:
			case 23:
				return null;
			case 25:
				$h = unpack( 'n', substr( $data, $offset, 2 ) )[1];
				$offset += 2;
				$exp  = ( $h >> 10 ) & 0x1f;
				$mant = $h & 0x3ff;
				$val  = 0 === $exp ? $mant * 2 ** -24 : ( 31 === $exp ? INF : ( $mant + 1024 ) * 2 ** ( $exp - 25 ) );
				return ( $h & 0x8000 ) ? -$val : $val;
			case 26:
				$v = unpack( 'G', substr( $data, $offset, 4 ) )[1];
				$offset += 4;
				return $v;
			case 27:
				$v = unpack( 'E', substr( $data, $offset, 8 ) )[1];
				$offset += 8;
				return $v;
			default:
				throw new \UnexpectedValueException( 'cbor: unsupported simple value' );
		}
	}
}
