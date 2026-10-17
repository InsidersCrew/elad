<?php
namespace Insiders\Collections\Integrations\Tranzila;

use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Response code -> failure class (§4 סיווג כשל). Checked against Tranzila's list
 * (docs.tranzila.com, transaction-response-codes, 2026-10-17); 033/036/039 are kept from
 * the older Shva list. The docs do not say which codes My Billing retries, so the
 * classes stay conservative (setting 'tranzila_code_map' overrides).
 * Anything unmapped is "unknown", and unknown never triggers an automatic message.
 */
final class ResponseCodes {

	public const SEED = array(
		'001' => 'card_invalid',     // כרטיס חסום
		'002' => 'suspected_fraud',  // כרטיס גנוב
		'003' => 'declined',         // התקשר לחברת האשראי
		'004' => 'declined',         // סירוב
		'005' => 'suspected_fraud',  // כרטיס מזויף
		'009' => 'technical',        // שגיאת תקשורת
		'015' => 'expired_card',     // פג תוקף (הקוד בתיעוד של טרנזילה)
		'033' => 'card_invalid',     // כרטיס לא תקין
		'036' => 'expired_card',     // פג תוקף
		'039' => 'card_invalid',     // ספרת ביקורת לא תקינה
	);

	public const LABELS = array(
		'technical'          => 'כשל טכני',
		'insufficient_funds' => 'חוסר מסגרת',
		'declined'           => 'סירוב',
		'expired_card'       => 'כרטיס שפג תוקפו',
		'card_invalid'       => 'כרטיס חסום או לא תקין',
		'suspected_fraud'    => 'חשד להונאה',
		'unknown'            => 'קוד לא מוכר',
	);

	/** Keep codes as strings with leading zeros: "000", not 0. */
	public static function normalize( $code ): string {
		$c = trim( (string) $code );
		return ( '' !== $c && ctype_digit( $c ) && strlen( $c ) < 3 ) ? str_pad( $c, 3, '0', STR_PAD_LEFT ) : $c;
	}

	public static function classify( string $code ): string {
		$map = json_decode( (string) Settings::get( 'tranzila_code_map', '' ), true );
		$map = is_array( $map ) ? array_replace( self::SEED, $map ) : self::SEED;
		return $map[ self::normalize( $code ) ] ?? 'unknown';
	}

	public static function is_success( string $code ): bool {
		return '000' === self::normalize( $code );
	}
}
