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

	/** Greeting rule (§9): first name only when reliable, otherwise "היי". */
	public static function greeting_name( array $c ): string {
		return ( ! empty( $c['first_name_reliable'] ) && ! empty( $c['first_name'] ) ) ? (string) $c['first_name'] : '';
	}
}
