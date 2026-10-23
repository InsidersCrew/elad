<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Phone;

defined( 'ABSPATH' ) || exit;

/**
 * Customers are identified by internal id plus explicit provider mappings (§2).
 * Phone, name or token alone never identify a person; a shared phone blocks
 * outreach until someone confirms who is who. Nothing is ever auto-merged.
 */
final class Customers {

	public static function get( int $id ): ?array {
		return Db::row( 'SELECT * FROM ' . Db::t( 'customers' ) . ' WHERE id = %d', $id );
	}

	/** Possible duplicates for the "new customer" form — shown, never merged. */
	public static function duplicates( ?string $phone, ?string $email, ?int $wp_user_id ): array {
		$e164  = $phone ? Phone::e164( $phone ) : null;
		$conds = array();
		$args  = array();
		if ( $e164 ) {
			$conds[] = 'phone_e164 = %s';
			$args[]  = $e164;
		}
		if ( $email ) {
			$conds[] = 'email = %s';
			$args[]  = strtolower( trim( $email ) );
		}
		if ( $wp_user_id ) {
			$conds[] = 'wp_user_id = %d';
			$args[]  = $wp_user_id;
		}
		if ( ! $conds ) {
			return array();
		}
		return Db::rows( 'SELECT id, full_name, phone_e164, email, wp_user_id FROM ' . Db::t( 'customers' ) . ' WHERE ' . implode( ' OR ', $conds ) . ' LIMIT 20', ...$args );
	}

	public static function create( array $d ): int {
		$name = trim( (string) ( $d['full_name'] ?? '' ) );
		if ( '' === $name ) {
			throw new DomainError( 'invalid', 'שם הלקוח חובה', 400, array( 'full_name' => 'חובה' ) );
		}
		$phone = isset( $d['phone'] ) && '' !== $d['phone'] ? Phone::e164( $d['phone'] ) : null;
		if ( isset( $d['phone'] ) && '' !== $d['phone'] && ! $phone ) {
			throw new DomainError( 'invalid', 'מספר הטלפון אינו תקין', 400, array( 'phone' => 'מספר לא תקין' ) );
		}
		$first = trim( (string) ( $d['first_name'] ?? '' ) );
		$id    = Db::insert(
			'customers',
			array(
				'wp_user_id'          => ! empty( $d['wp_user_id'] ) ? (int) $d['wp_user_id'] : null,
				'full_name'           => mb_substr( $name, 0, 190 ),
				'first_name'          => '' !== $first ? mb_substr( $first, 0, 100 ) : null,
				'first_name_reliable' => ! empty( $d['first_name_reliable'] ) && '' !== $first ? 1 : 0,
				'phone_e164'          => $phone,
				'email'               => ! empty( $d['email'] ) ? strtolower( trim( $d['email'] ) ) : null,
				'contact_status'      => ! empty( $d['phone_verified'] ) && $phone ? 'verified' : 'unverified',
				'contact_verified_at' => ! empty( $d['phone_verified'] ) && $phone ? Clock::utc() : null,
				'owner_id'            => ! empty( $d['owner_id'] ) ? (int) $d['owner_id'] : null,
				'pipedrive_person_id' => ! empty( $d['pipedrive_person_id'] ) ? (int) $d['pipedrive_person_id'] : null,
				'created_at'          => Clock::utc(),
				'updated_at'          => Clock::utc(),
			)
		);
		if ( ! empty( $d['wp_user_id'] ) ) {
			self::map_identity( $id, 'wp', '', (string) (int) $d['wp_user_id'], true );
		}
		if ( ! empty( $d['contact_permission_ref'] ) ) {
			self::set_permission( $id, 'whatsapp', true, (string) $d['contact_permission_ref'] );
		}
		Audit::log( 'customer.create', 'customer', $id, null, array( 'name' => $name, 'phone' => Phone::mask( $phone ) ), '' );
		return $id;
	}

	public static function verify_contact( int $id, string $note ): void {
		Db::update( 'customers', array( 'contact_status' => 'verified', 'contact_verified_at' => Clock::utc(), 'updated_at' => Clock::utc() ), array( 'id' => $id ) );
		Audit::log( 'customer.contact_verified', 'customer', $id, null, null, $note );
	}

	public static function mark_wrong_number( int $id, string $note ): void {
		Db::update( 'customers', array( 'contact_status' => 'wrong_number', 'updated_at' => Clock::utc() ), array( 'id' => $id ) );
		self::set_permission( $id, 'whatsapp', false, 'wrong_number: ' . $note );
		Audit::log( 'customer.wrong_number', 'customer', $id, null, null, $note );
	}

	public static function map_identity( int $customer_id, string $provider, string $tenant, string $external_id, bool $verified ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Db::t( 'external_identities' ) . ' (customer_id, provider, tenant_or_terminal, external_id, verified_at, created_at) VALUES (%d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE id = id',
				$customer_id,
				$provider,
				$tenant,
				$external_id,
				$verified ? Clock::utc() : null,
				Clock::utc()
			)
		);
	}

	public static function by_identity( string $provider, string $tenant, string $external_id ): ?int {
		$id = Db::value( 'SELECT customer_id FROM ' . Db::t( 'external_identities' ) . ' WHERE provider = %s AND tenant_or_terminal = %s AND external_id = %s', $provider, $tenant, $external_id );
		return $id ? (int) $id : null;
	}

	/** All customers on a phone. More than one = identity must be confirmed before any outreach. */
	public static function by_phone( string $e164 ): array {
		return Db::rows( 'SELECT * FROM ' . Db::t( 'customers' ) . ' WHERE phone_e164 = %s', $e164 );
	}

	public static function shares_phone( array $c ): bool {
		if ( empty( $c['phone_e164'] ) ) {
			return false;
		}
		return (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'customers' ) . ' WHERE phone_e164 = %s AND id <> %d', $c['phone_e164'], (int) $c['id'] ) > 0;
	}

	public static function set_permission( int $customer_id, string $channel, bool $allowed, string $source ): void {
		global $wpdb;
		$now = Clock::utc();
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Db::t( 'contact_preferences' ) . ' (customer_id, channel, allowed, source, captured_at, revoked_at) VALUES (%d, %s, %d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE allowed = VALUES(allowed), source = VALUES(source), captured_at = VALUES(captured_at), revoked_at = VALUES(revoked_at)',
				$customer_id,
				$channel,
				$allowed ? 1 : 0,
				mb_substr( $source, 0, 190 ),
				$now,
				$allowed ? null : $now
			)
		);
		Audit::log( $allowed ? 'contact.allow' : 'contact.revoke', 'customer', $customer_id, null, array( 'channel' => $channel ), $source );
	}

	/** null = no recorded permission (blocks proactive outreach), true/false = recorded decision. */
	public static function permission( int $customer_id, string $channel ): ?bool {
		$row = Db::row( 'SELECT allowed FROM ' . Db::t( 'contact_preferences' ) . ' WHERE customer_id = %d AND channel = %s', $customer_id, $channel );
		return $row ? (bool) $row['allowed'] : null;
	}

	/** WATI said the number is not on WhatsApp (or a rep marked it): messages go by email instead. */
	public static function no_whatsapp( int $id ): bool {
		return '' !== (string) Db::value( 'SELECT whatsapp_missing_at FROM ' . Db::t( 'customers' ) . ' WHERE id = %d', $id );
	}

	/** The address journey emails go to: a valid one the student did not ask to stop. */
	public static function journey_email( int $id ): string {
		$mail = trim( (string) Db::value( 'SELECT email FROM ' . Db::t( 'customers' ) . ' WHERE id = %d', $id ) );
		return ( is_email( $mail ) && false !== self::permission( $id, 'email' ) ) ? $mail : '';
	}

	/** 'email' for a student without WhatsApp who has an email address; 'whatsapp' otherwise. */
	public static function channel( int $id ): string {
		return self::no_whatsapp( $id ) && '' !== self::journey_email( $id ) ? 'email' : 'whatsapp';
	}

	/**
	 * The number is not on WhatsApp. From WATI's answer this happens automatically, but many students at
	 * once is WATI's problem (the account, the sending number), not theirs: after five in three hours no more
	 * are moved, and an exception says why. Without an email address a person reaches the student by phone.
	 */
	public static function mark_no_whatsapp( int $id, string $why, string $source = 'wati' ): bool {
		$c = self::get( $id );
		if ( ! $c ) {
			return false;
		}
		if ( ! empty( $c['whatsapp_missing_at'] ) ) {
			return true;
		}
		if ( 'wati' === $source && (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'customers' ) . ' WHERE whatsapp_missing_at >= %s', Clock::utc( Clock::now() - 3 * HOUR_IN_SECONDS ) ) >= 5 ) {
			Exceptions::open( 'no_whatsapp_burst:' . Clock::today(), 'integration_failure', 'high', 'WATI ענה "המספר לא מחובר לוואטסאפ" לחמישה תלמידים או יותר בשלוש שעות. כנראה בעיה בחשבון או במספר השולח ב-WATI, לא אצל התלמידים, ולכן תלמידים נוספים לא עוברים למייל אוטומטית. ההודעות שלהם יתוכננו שוב', array() );
			return false;
		}
		Db::update( 'customers', array( 'whatsapp_missing_at' => Clock::utc(), 'updated_at' => Clock::utc() ), array( 'id' => $id ) );
		Audit::log( 'customer.no_whatsapp', 'customer', $id, null, array( 'source' => $source ), mb_substr( $why, 0, 250 ) );
		if ( '' === self::journey_email( $id ) ) {
			$case = (int) Db::value( 'SELECT id FROM ' . Db::t( 'cases' ) . " WHERE customer_id = %d AND workflow_state <> 'closed' ORDER BY id DESC LIMIT 1", $id );
			Tasks::open( 'no_whatsapp:' . $id, 'reply_review', array( 'case_id' => $case ?: null, 'customer_id' => $id, 'priority' => 'high', 'reason' => 'למספר של התלמיד/ה אין וואטסאפ, ואין מייל תקין בכרטיס. לפנות בטלפון, ולהוסיף מייל בכרטיס הלקוח כדי שההודעות יעברו למייל.' ) );
		}
		return true;
	}

	/** WhatsApp after all (the student wrote on it, or a rep says so): back to WhatsApp. */
	public static function clear_no_whatsapp( int $id, string $why ): void {
		if ( 1 === Db::exec( 'UPDATE ' . Db::t( 'customers' ) . ' SET whatsapp_missing_at = NULL, updated_at = %s WHERE id = %d AND whatsapp_missing_at IS NOT NULL', Clock::utc(), $id ) ) {
			Audit::log( 'customer.whatsapp_back', 'customer', $id, null, null, mb_substr( $why, 0, 250 ) );
		}
	}

	/** Greeting rule (§9): first name only when reliable, otherwise "היי". */
	public static function greeting_name( array $c ): string {
		return ( ! empty( $c['first_name_reliable'] ) && ! empty( $c['first_name'] ) ) ? (string) $c['first_name'] : '';
	}
}
