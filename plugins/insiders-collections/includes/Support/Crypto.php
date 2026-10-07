<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Secretbox encryption for card tokens, raw provider payloads and API keys.
 * The key lives in wp-config.php (ICOL_ENCRYPTION_KEY = 'base64:...'), never in
 * the database, so a DB dump alone does not expose tokens.
 *
 * Without a key the plugin refuses to store anything that needs encryption —
 * it redacts instead. Storing a token in clear "temporarily" is how it stays forever.
 */
final class Crypto {

	public static function available(): bool {
		return null !== self::key();
	}

	private static function key(): ?string {
		if ( ! defined( 'ICOL_ENCRYPTION_KEY' ) || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		$raw = (string) ICOL_ENCRYPTION_KEY;
		if ( str_starts_with( $raw, 'base64:' ) ) {
			$raw = base64_decode( substr( $raw, 7 ), true );
		}
		if ( ! is_string( $raw ) || strlen( $raw ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			return null;
		}
		return $raw;
	}

	public static function encrypt( string $plain ): string {
		$key = self::key();
		if ( null === $key ) {
			throw new \RuntimeException( 'ICOL_ENCRYPTION_KEY missing or invalid' );
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
	}

	public static function decrypt( string $blob ): ?string {
		$key = self::key();
		if ( null === $key || ! str_starts_with( $blob, 'v1:' ) ) {
			return null;
		}
		$bin = base64_decode( substr( $blob, 3 ), true );
		if ( false === $bin || strlen( $bin ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( substr( $bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
		return false === $plain ? null : $plain;
	}

	/** Keyed fingerprint so equal tokens are detectable without storing them in clear. */
	public static function fingerprint( string $value ): string {
		$key = self::key() ?? wp_salt( 'auth' );
		return hash_hmac( 'sha256', $value, $key );
	}

	/** Remove card material from a provider payload before storing it unencrypted anywhere. */
	public static function redact_payload( array $payload ): array {
		$sensitive = array( 'tranzilatk', 'token', 'expdate', 'expmonth', 'expyear', 'ccno', 'cardnum', 'card_number', 'mycvv', 'cvv', 'myid' );
		foreach ( $payload as $k => $v ) {
			if ( in_array( strtolower( (string) $k ), $sensitive, true ) ) {
				$payload[ $k ] = '[redacted]';
			} elseif ( is_array( $v ) ) {
				$payload[ $k ] = self::redact_payload( $v );
			}
		}
		return $payload;
	}
}
