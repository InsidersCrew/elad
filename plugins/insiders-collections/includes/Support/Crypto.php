<?php
namespace Insiders\Collections\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Secretbox encryption for card tokens, raw provider payloads and API keys.
 * The key never lives in the database, so a DB dump alone does not expose tokens.
 * Where it lives, in order: ICOL_ENCRYPTION_KEY in wp-config.php if someone defined
 * it; otherwise wp-content/icol-encryption-key.php, which the plugin creates itself
 * (editing wp-config.php was a step most site owners cannot do). The file returns the
 * key to PHP and prints nothing when opened from the web.
 *
 * Without a key the plugin refuses to store anything that needs encryption —
 * it redacts instead. Storing a token in clear "temporarily" is how it stays forever.
 */
final class Crypto {

	public const KEY_FILE = 'icol-encryption-key.php';

	/** Tests only: read and create the key file somewhere else. */
	public static ?string $key_path_override = null;

	private static ?string $file_key = null;

	public static function available(): bool {
		return null !== self::key();
	}

	/** 'wp-config' | 'file' | 'none' — for the health screen. */
	public static function source(): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'none';
		}
		if ( defined( 'ICOL_ENCRYPTION_KEY' ) ) {
			return null !== self::decode( (string) ICOL_ENCRYPTION_KEY ) ? 'wp-config' : 'none';
		}
		return null !== self::from_file() ? 'file' : 'none';
	}

	public static function key_path(): string {
		return self::$key_path_override ?? trailingslashit( WP_CONTENT_DIR ) . self::KEY_FILE;
	}

	private static function key(): ?string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return null;
		}
		// A constant, when present, always wins; a different file key next to it is never mixed in.
		if ( defined( 'ICOL_ENCRYPTION_KEY' ) ) {
			return self::decode( (string) ICOL_ENCRYPTION_KEY );
		}
		return self::from_file();
	}

	private static function decode( string $raw ): ?string {
		if ( str_starts_with( $raw, 'base64:' ) ) {
			$raw = base64_decode( substr( $raw, 7 ), true );
		}
		if ( ! is_string( $raw ) || strlen( $raw ) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
			return null;
		}
		return $raw;
	}

	private static function from_file(): ?string {
		if ( null !== self::$file_key ) {
			return self::$file_key;
		}
		$path = self::key_path();
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$v = include $path;
		$k = is_string( $v ) ? self::decode( $v ) : null;
		if ( null !== $k ) {
			self::$file_key = $k;
		}
		return $k;
	}

	public static function flush(): void {
		self::$file_key = null;
	}

	/**
	 * Creates the key file once, when no key exists anywhere. 'x' mode makes two
	 * simultaneous requests safe: only one creates it, the other reads it.
	 * Never overwrites: replacing a key makes everything stored with it unreadable.
	 */
	public static function ensure_key_file(): bool {
		if ( defined( 'ICOL_ENCRYPTION_KEY' ) || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return false;
		}
		$path = self::key_path();
		if ( file_exists( $path ) ) {
			return null !== self::from_file();
		}
		$h = @fopen( $path, 'x' ); // phpcs:ignore -- a failure is reported by the health screen
		if ( ! $h ) {
			return false;
		}
		$body = "<?php\n"
			. "// INSIDERS Collections: encryption key for stored tokens and API keys.\n"
			. "// Do not delete or edit this file. Without it, saved provider keys must be entered again.\n"
			. "defined( 'ABSPATH' ) || exit;\n"
			. "return 'base64:" . base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) . "';\n";
		fwrite( $h, $body );
		fclose( $h );
		@chmod( $path, 0640 ); // phpcs:ignore
		self::flush();
		return null !== self::from_file();
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
