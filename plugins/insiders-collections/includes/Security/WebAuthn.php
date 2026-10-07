<?php
namespace Insiders\Collections\Security;

defined( 'ABSPATH' ) || exit;

/**
 * WebAuthn (passkeys) relying-party checks, without a library: CBOR + COSE ->
 * SubjectPublicKeyInfo PEM, then openssl / sodium for the signature.
 *
 * What is verified, both ceremonies: clientData type, challenge, origin; rpIdHash;
 * user present AND user verified (Face ID / fingerprint / phone PIN, never a bare tap).
 * Attestation statements are not verified (attestation 'none' is requested): trust in
 * a phone comes from the pairing ceremony (code typed on the computer + owner approval),
 * not from the authenticator vendor.
 */
final class WebAuthn {

	public const ALG_ES256 = -7;
	public const ALG_RS256 = -257;
	public const ALG_EDDSA = -8;

	public static function b64u_encode( string $bin ): string {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	public static function b64u_decode( string $s ): string {
		$s = strtr( $s, '-_', '+/' );
		$r = base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		if ( false === $r ) {
			throw new \UnexpectedValueException( 'bad base64url' );
		}
		return $r;
	}

	public static function rp_id(): string {
		if ( defined( 'ICOL_GATE_RP_ID' ) && ICOL_GATE_RP_ID ) {
			return (string) ICOL_GATE_RP_ID;
		}
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/** Origins a phone page can legitimately run on: home and site URL (scheme://host[:port]). */
	public static function origins(): array {
		$out = array();
		foreach ( array( home_url(), site_url() ) as $u ) {
			$p = wp_parse_url( $u );
			if ( empty( $p['host'] ) ) {
				continue;
			}
			$out[] = strtolower( ( $p['scheme'] ?? 'https' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) );
		}
		return array_values( array_unique( $out ) );
	}

	/** Passkeys need a secure context: https, or localhost for development. */
	public static function secure_context(): bool {
		$host = self::rp_id();
		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) || 'localhost' === $host || str_ends_with( $host, '.localhost' );
	}

	/**
	 * Registration. Returns the credential to store.
	 *
	 * @param array  $cred      {id, rawId, response: {clientDataJSON, attestationObject}} as base64url strings
	 * @param string $challenge the raw challenge bytes we issued
	 */
	public static function verify_registration( array $cred, string $challenge ): array {
		$client = self::client_data( (string) ( $cred['response']['clientDataJSON'] ?? '' ), 'webauthn.create', $challenge );
		$att    = Cbor::decode( self::b64u_decode( (string) ( $cred['response']['attestationObject'] ?? '' ) ) );
		if ( ! is_array( $att ) || ! isset( $att['authData'] ) || ! is_string( $att['authData'] ) ) {
			throw new \UnexpectedValueException( 'attestation object without authData' );
		}
		$auth = self::auth_data( $att['authData'], true );
		$key  = self::cose_to_key( $auth['credential_public_key'] );
		$raw  = self::b64u_decode( (string) ( $cred['rawId'] ?? $cred['id'] ?? '' ) );
		if ( ! hash_equals( $raw, $auth['credential_id'] ) ) {
			throw new \UnexpectedValueException( 'credential id mismatch' );
		}
		unset( $client );
		return array(
			'credential_id'   => self::b64u_encode( $auth['credential_id'] ),
			'public_key'      => $key['pem'],
			'alg'             => $key['alg'],
			'sign_count'      => $auth['sign_count'],
			'aaguid'          => $auth['aaguid'],
			'backup_eligible' => $auth['flags']['be'],
		);
	}

	/**
	 * Authentication. Returns the new signature counter.
	 *
	 * @param array $cred   {rawId, response: {clientDataJSON, authenticatorData, signature, userHandle?}}
	 * @param array $device stored row: public_key, alg, sign_count
	 */
	public static function verify_assertion( array $cred, string $challenge, array $device ): int {
		$client_json = self::b64u_decode( (string) ( $cred['response']['clientDataJSON'] ?? '' ) );
		self::client_data( (string) ( $cred['response']['clientDataJSON'] ?? '' ), 'webauthn.get', $challenge );
		$auth_raw = self::b64u_decode( (string) ( $cred['response']['authenticatorData'] ?? '' ) );
		$auth     = self::auth_data( $auth_raw, false );
		$sig      = self::b64u_decode( (string) ( $cred['response']['signature'] ?? '' ) );
		$signed   = $auth_raw . hash( 'sha256', $client_json, true );
		if ( ! self::verify_signature( $signed, $sig, (string) $device['public_key'], (int) $device['alg'] ) ) {
			throw new \UnexpectedValueException( 'signature' );
		}
		$stored = (int) $device['sign_count'];
		// Counters are optional (synced passkeys send 0). If either side counts, it must move forward:
		// a counter that goes back means a cloned authenticator.
		if ( ( $stored > 0 || $auth['sign_count'] > 0 ) && $auth['sign_count'] <= $stored ) {
			throw new \UnexpectedValueException( 'sign_count' );
		}
		return $auth['sign_count'];
	}

	private static function client_data( string $b64, string $type, string $challenge ): array {
		$c = json_decode( self::b64u_decode( $b64 ), true );
		if ( ! is_array( $c ) || ( $c['type'] ?? '' ) !== $type ) {
			throw new \UnexpectedValueException( 'clientData type' );
		}
		if ( ! hash_equals( self::b64u_encode( $challenge ), (string) ( $c['challenge'] ?? '' ) ) ) {
			throw new \UnexpectedValueException( 'challenge' );
		}
		if ( ! in_array( strtolower( (string) ( $c['origin'] ?? '' ) ), self::origins(), true ) ) {
			throw new \UnexpectedValueException( 'origin' );
		}
		if ( ! empty( $c['crossOrigin'] ) ) {
			throw new \UnexpectedValueException( 'cross-origin' );
		}
		return $c;
	}

	private static function auth_data( string $d, bool $expect_credential ): array {
		if ( strlen( $d ) < 37 ) {
			throw new \UnexpectedValueException( 'authData too short' );
		}
		if ( ! hash_equals( hash( 'sha256', self::rp_id(), true ), substr( $d, 0, 32 ) ) ) {
			throw new \UnexpectedValueException( 'rpIdHash' );
		}
		$f     = ord( $d[32] );
		$flags = array( 'up' => (bool) ( $f & 0x01 ), 'uv' => (bool) ( $f & 0x04 ), 'be' => (bool) ( $f & 0x08 ), 'bs' => (bool) ( $f & 0x10 ), 'at' => (bool) ( $f & 0x40 ) );
		if ( ! $flags['up'] || ! $flags['uv'] ) {
			throw new \UnexpectedValueException( 'user verification' );
		}
		$out = array( 'flags' => $flags, 'sign_count' => unpack( 'N', substr( $d, 33, 4 ) )[1] );
		if ( $expect_credential ) {
			if ( ! $flags['at'] || strlen( $d ) < 55 ) {
				throw new \UnexpectedValueException( 'no attested credential' );
			}
			$aaguid = bin2hex( substr( $d, 37, 16 ) );
			$len    = unpack( 'n', substr( $d, 53, 2 ) )[1];
			if ( $len < 16 || $len > 1023 || strlen( $d ) < 55 + $len ) {
				throw new \UnexpectedValueException( 'credential id length' );
			}
			$offset = 55 + $len;
			$start  = $offset;
			$cose   = Cbor::decode( $d, $offset );
			$out['aaguid']                = vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( $aaguid, 4 ) );
			$out['credential_id']         = substr( $d, 55, $len );
			$out['credential_public_key'] = is_array( $cose ) ? $cose : array();
			$out['cose_len']              = $offset - $start;
		}
		return $out;
	}

	/** COSE_Key -> PEM SubjectPublicKeyInfo (or raw Ed25519 key, base64, for sodium). */
	public static function cose_to_key( array $k ): array {
		$kty = $k[1] ?? null;
		$alg = $k[3] ?? null;
		if ( 2 === $kty && self::ALG_ES256 === $alg && 1 === ( $k[-1] ?? null ) ) {
			$x = (string) ( $k[-2] ?? '' );
			$y = (string) ( $k[-3] ?? '' );
			if ( 32 !== strlen( $x ) || 32 !== strlen( $y ) ) {
				throw new \UnexpectedValueException( 'EC point size' );
			}
			$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . "\x04" . $x . $y;
			return array( 'alg' => $alg, 'pem' => self::pem( $der ) );
		}
		if ( 3 === $kty && self::ALG_RS256 === $alg ) {
			$n   = (string) ( $k[-1] ?? '' );
			$e   = (string) ( $k[-2] ?? '' );
			$rsa = self::der( 0x30, self::der_int( $n ) . self::der_int( $e ) );
			$der = self::der( 0x30, self::der( 0x30, hex2bin( '06092a864886f70d0101010500' ) ) . self::der( 0x03, "\x00" . $rsa ) );
			return array( 'alg' => $alg, 'pem' => self::pem( $der ) );
		}
		if ( 1 === $kty && self::ALG_EDDSA === $alg && 6 === ( $k[-1] ?? null ) && 32 === strlen( (string) ( $k[-2] ?? '' ) ) ) {
			return array( 'alg' => $alg, 'pem' => 'ed25519:' . base64_encode( (string) $k[-2] ) );
		}
		throw new \UnexpectedValueException( 'unsupported key type' );
	}

	private static function verify_signature( string $data, string $sig, string $key, int $alg ): bool {
		if ( self::ALG_EDDSA === $alg ) {
			return str_starts_with( $key, 'ed25519:' ) && function_exists( 'sodium_crypto_sign_verify_detached' ) && 64 === strlen( $sig )
				&& sodium_crypto_sign_verify_detached( $sig, $data, base64_decode( substr( $key, 8 ) ) );
		}
		$pub = openssl_pkey_get_public( $key );
		return $pub && 1 === openssl_verify( $data, $sig, $pub, OPENSSL_ALGO_SHA256 );
	}

	private static function pem( string $der ): string {
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	private static function der( int $tag, string $body ): string {
		$l = strlen( $body );
		if ( $l < 128 ) {
			return chr( $tag ) . chr( $l ) . $body;
		}
		$b = ltrim( pack( 'N', $l ), "\x00" );
		return chr( $tag ) . chr( 0x80 | strlen( $b ) ) . $b . $body;
	}

	private static function der_int( string $bytes ): string {
		$bytes = ltrim( $bytes, "\x00" );
		if ( '' === $bytes || ord( $bytes[0] ) & 0x80 ) {
			$bytes = "\x00" . $bytes;
		}
		return self::der( 0x02, $bytes );
	}
}
