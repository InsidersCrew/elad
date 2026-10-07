<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Integrations\Tranzila\Client as TranzilaClient;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Crypto;
use Insiders\Collections\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Settling a payment and fixing the card for future charges are separate (§7).
 * A successful charge on another card proves nothing about the standing order.
 * Manual confirmation ("confirmed_manual") and provider verification ("verified")
 * are different states and are never merged.
 */
final class CardTasks {

	public static function maybe_create( int $payment_id ): array {
		$p = Ledger::payment( $payment_id );
		if ( ! $p || 'verified' !== $p['status'] || empty( $p['customer_id'] ) ) {
			return array();
		}
		$orders = Db::rows( 'SELECT * FROM ' . Db::t( 'recurring_orders' ) . " WHERE customer_id = %d AND status = 'active'", (int) $p['customer_id'] );
		$created = array();
		foreach ( $orders as $o ) {
			// A charge made by this standing order itself proves the order's card works.
			if ( 'sto' === $p['origin'] && (int) $p['recurring_order_id'] === (int) $o['id'] ) {
				continue;
			}
			if ( in_array( $o['card_status'], array( 'verified', 'confirmed_manual', 'not_applicable' ), true ) ) {
				continue;
			}
			$future_known = null !== $o['future_installments'] || null !== $o['next_due_at'];
			$has_future   = ( (int) $o['future_installments'] > 0 ) || ( null !== $o['next_due_at'] && $o['next_due_at'] >= Clock::today() );
			$state        = ! $future_known ? 'needs_clarification' : ( $has_future ? 'open' : 'not_required' );
			$policy       = Policy::effective_or_draft();
			$soon         = $o['next_due_at'] && Calendar::business_days_between( Clock::today(), $o['next_due_at'], $policy ) <= 5;
			$due          = Clock::local_to_ts( Calendar::add_business_days( Clock::today(), (int) ( \Insiders\Collections\Support\Settings::get( 'card_task_due_business_days' ) ?: 1 ), $policy ), $policy['window_end'] );
			global $wpdb;
			$wpdb->query(
				$wpdb->prepare(
					'INSERT IGNORE INTO ' . Db::t( 'card_update_tasks' ) . ' (recurring_order_id, source_payment_id, state, due_at, urgency, note, created_at, updated_at) VALUES (%d, %d, %s, %s, %s, %s, %s, %s)',
					(int) $o['id'],
					$payment_id,
					$state,
					Clock::utc( $due ),
					$soon ? 'high' : 'normal',
					$p['has_reusable_token'] ? null : 'התשלום בוצע באמצעי שאינו מספק טוקן לשימוש חוזר — נדרש כרטיס מהלקוח',
					Clock::utc(),
					Clock::utc()
				)
			);
			if ( $wpdb->insert_id ) {
				$created[] = (int) $wpdb->insert_id;
				if ( 'open' === $state || 'needs_clarification' === $state ) {
					Db::update( 'recurring_orders', array( 'card_status' => 'update_required', 'card_status_basis' => 'payment:' . $payment_id, 'updated_at' => Clock::utc() ), array( 'id' => (int) $o['id'] ) );
					$case = Db::value( 'SELECT id FROM ' . Db::t( 'cases' ) . ' WHERE recurring_order_id = %d ORDER BY id DESC LIMIT 1', (int) $o['id'] );
					Tasks::open(
						'card_update:' . $o['id'] . ':' . $payment_id,
						'card_update',
						array(
							'case_id'     => $case ? (int) $case : null,
							'customer_id' => (int) $p['customer_id'],
							'priority'    => $soon ? 'high' : 'normal',
							'reason'      => 'התקבל תשלום של ' . \Insiders\Collections\Support\Money::format( (int) $p['amount_minor'] ) . ' ₪. הוראה ' . $o['sto_id'] . ' במסוף ' . $o['terminal'] . ': לבדוק ולעדכן אמצעי תשלום לחיובים הבאים.',
						)
					);
				}
			}
		}
		return $created;
	}

	/** Store the token from the paying transaction, encrypted, bound to its terminal. */
	public static function store_credential( int $payment_id, string $terminal, string $token, ?string $expdate ): ?int {
		if ( '' === $token || ! Crypto::available() ) {
			return null;
		}
		[ $mm, $yy ] = self::parse_expiry( $expdate );
		$fp          = Crypto::fingerprint( $terminal . '|' . $token );
		$existing    = Db::value( 'SELECT id FROM ' . Db::t( 'card_credentials' ) . ' WHERE fingerprint = %s', $fp );
		if ( $existing ) {
			return (int) $existing;
		}
		return Db::insert(
			'card_credentials',
			array(
				'terminal'          => $terminal,
				'encrypted_token'   => Crypto::encrypt( $token ),
				'expiry_month'      => $mm,
				'expiry_year'       => $yy,
				'last4'             => strlen( $token ) >= 4 ? substr( $token, -4 ) : null,
				'source_payment_id' => $payment_id,
				'fingerprint'       => $fp,
				'created_at'        => Clock::utc(),
			)
		);
	}

	/** Tranzila sends expdate as MMYY ("0426"). Normalized to month + 4-digit year after a format check. */
	public static function parse_expiry( ?string $raw ): array {
		$raw = preg_replace( '/\D/', '', (string) $raw );
		if ( 4 === strlen( $raw ) ) {
			$mm = (int) substr( $raw, 0, 2 );
			$yy = 2000 + (int) substr( $raw, 2, 2 );
			return ( $mm >= 1 && $mm <= 12 ) ? array( $mm, $yy ) : array( null, null );
		}
		if ( 6 === strlen( $raw ) ) {
			$mm = (int) substr( $raw, 0, 2 );
			$yy = (int) substr( $raw, 2, 4 );
			return ( $mm >= 1 && $mm <= 12 ) ? array( $mm, $yy ) : array( null, null );
		}
		return array( null, null );
	}

	public static function confirm( int $task_id, array $d ): void {
		if ( ! current_user_can( 'icol_card_confirm' ) ) {
			throw new DomainError( 'forbidden', 'אישור עדכון כרטיס מותר לאחראי גבייה או מנהל', 403 );
		}
		$t = Db::row( 'SELECT * FROM ' . Db::t( 'card_update_tasks' ) . ' WHERE id = %d', $task_id );
		if ( ! $t ) {
			throw new DomainError( 'not_found', 'המשימה לא נמצאה', 404 );
		}
		$o    = Db::row( 'SELECT * FROM ' . Db::t( 'recurring_orders' ) . ' WHERE id = %d', (int) $t['recurring_order_id'] );
		$type = (string) ( $d['confirmation_type'] ?? '' );
		if ( 'verified' === $type ) {
			// §19: the browser never declares "verified". Only the provider check can.
			throw new DomainError( 'forbidden', 'אימות מול ספק נקבע רק מתוצאת בדיקה מול טרנזילה', 403 );
		}
		if ( ! in_array( $type, array( 'confirmed_manual', 'not_required' ), true ) ) {
			throw new DomainError( 'validation_failed', 'סוג אישור לא תקין', 400, array( 'confirmation_type' => 'לא תקין' ) );
		}
		if ( (int) ( $d['recurring_order_id'] ?? 0 ) !== (int) $t['recurring_order_id'] || (int) ( $d['source_payment_id'] ?? 0 ) !== (int) $t['source_payment_id'] ) {
			throw new DomainError( 'validation_failed', 'מזהה ההוראה או התשלום אינו תואם למשימה', 400, array( 'recurring_order_id' => 'לא תואם' ) );
		}
		if ( 'confirmed_manual' === $type && ( (string) ( $d['sto_id'] ?? '' ) !== (string) $o['sto_id'] ) ) {
			throw new DomainError( 'validation_failed', 'יש להקליד את מזהה ההוראה שעודכנה', 400, array( 'sto_id' => 'לא תואם להוראה' ) );
		}
		if ( '' === trim( (string) ( $d['evidence_ref'] ?? '' ) ) && 'confirmed_manual' === $type ) {
			throw new DomainError( 'validation_failed', 'נדרשת אסמכתה לעדכון', 400, array( 'evidence_ref' => 'חובה' ) );
		}
		if ( 'not_required' === $type && '' === trim( (string) ( $d['note'] ?? '' ) ) ) {
			throw new DomainError( 'validation_failed', 'יש להסביר למה לא נדרש עדכון', 400, array( 'note' => 'חובה' ) );
		}
		Db::transaction(
			function () use ( $t, $o, $type, $d, $task_id ) {
				Db::update(
					'card_update_tasks',
					array(
						'state'             => $type,
						'confirmation_type' => $type,
						'confirmation_ref'  => mb_substr( (string) ( $d['evidence_ref'] ?? '' ), 0, 255 ),
						'confirmed_by'      => get_current_user_id(),
						'confirmed_at'      => Clock::utc(),
						'note'              => mb_substr( (string) ( $d['note'] ?? '' ), 0, 500 ),
						'updated_at'        => Clock::utc(),
					),
					array( 'id' => $task_id )
				);
				if ( 'confirmed_manual' === $type ) {
					Db::update( 'recurring_orders', array( 'card_status' => 'confirmed_manual', 'card_status_basis' => 'task:' . $task_id, 'updated_at' => Clock::utc() ), array( 'id' => (int) $o['id'] ) );
				}
				Tasks::close_by_key( 'card_update:' . $t['recurring_order_id'] . ':' . $t['source_payment_id'], $type );
				Audit::log( 'card_task.' . $type, 'card_task', $task_id, array( 'state' => $t['state'] ), array( 'state' => $type, 'sto' => $o['sto_id'] ), (string) ( $d['note'] ?? '' ) );
			}
		);
	}

	/**
	 * Upgrade to "verified" only when Tranzila shows this standing order now uses the
	 * token from the source payment, on the same terminal (AT45: another terminal is not proof).
	 */
	public static function verify_with_provider( int $task_id ): array {
		$t    = Db::row( 'SELECT * FROM ' . Db::t( 'card_update_tasks' ) . ' WHERE id = %d', $task_id );
		$o    = Db::row( 'SELECT * FROM ' . Db::t( 'recurring_orders' ) . ' WHERE id = %d', (int) $t['recurring_order_id'] );
		$cred = Db::row( 'SELECT * FROM ' . Db::t( 'card_credentials' ) . ' WHERE source_payment_id = %d ORDER BY id DESC LIMIT 1', (int) $t['source_payment_id'] );
		if ( ! $cred ) {
			return array( 'verified' => false, 'reason' => 'אין טוקן שמור מהתשלום — לא ניתן להשוות' );
		}
		if ( $cred['terminal'] !== $o['terminal'] ) {
			return array( 'verified' => false, 'reason' => 'הטוקן נוצר במסוף ' . $cred['terminal'] . ' וההוראה במסוף ' . $o['terminal'] . ' — טוקן קשור למסוף' );
		}
		$sto = TranzilaClient::get_sto( $o['terminal'], $o['sto_id'] );
		if ( ! $sto['ok'] ) {
			return array( 'verified' => false, 'reason' => 'שגיאה בשליפת ההוראה: ' . $sto['error'] );
		}
		$token = (string) ( $sto['sto']['card']['token'] ?? '' );
		if ( '' === $token ) {
			return array( 'verified' => false, 'reason' => 'טרנזילה לא החזירה טוקן להוראה' );
		}
		if ( ! hash_equals( $cred['fingerprint'], Crypto::fingerprint( $o['terminal'] . '|' . $token ) ) ) {
			return array( 'verified' => false, 'reason' => 'הטוקן בהוראה שונה מהטוקן של התשלום' );
		}
		Db::update( 'card_update_tasks', array( 'state' => 'verified', 'confirmation_type' => 'verified', 'confirmed_at' => Clock::utc(), 'updated_at' => Clock::utc() ), array( 'id' => $task_id ) );
		Db::update( 'recurring_orders', array( 'card_status' => 'verified', 'card_status_basis' => 'provider_readback', 'updated_at' => Clock::utc() ), array( 'id' => (int) $o['id'] ) );
		Tasks::close_by_key( 'card_update:' . $t['recurring_order_id'] . ':' . $t['source_payment_id'], 'אומת מול טרנזילה' );
		Audit::log( 'card_task.verified', 'card_task', $task_id, null, null, 'provider readback' );
		return array( 'verified' => true, 'reason' => 'ההוראה משתמשת בטוקן מהתשלום' );
	}

	/** Protected token view: separate capability, recent password re-entry, audited (§3). */
	public static function reveal( int $task_id ): array {
		if ( ! current_user_can( 'icol_reveal_token' ) ) {
			throw new DomainError( 'forbidden', 'אין הרשאה לצפייה בטוקן', 403 );
		}
		$until = (int) get_user_meta( get_current_user_id(), 'icol_reauth_until', true );
		if ( $until < time() ) {
			throw new DomainError( 'reauth_required', 'יש להזין סיסמה מחדש לפני צפייה בטוקן', 401 );
		}
		$t    = Db::row( 'SELECT * FROM ' . Db::t( 'card_update_tasks' ) . ' WHERE id = %d', $task_id );
		$cred = $t ? Db::row( 'SELECT * FROM ' . Db::t( 'card_credentials' ) . ' WHERE source_payment_id = %d ORDER BY id DESC LIMIT 1', (int) $t['source_payment_id'] ) : null;
		if ( ! $cred ) {
			throw new DomainError( 'not_found', 'אין טוקן שמור למשימה', 404 );
		}
		$token = Crypto::decrypt( $cred['encrypted_token'] );
		Audit::log( 'token.reveal', 'card_task', $task_id, null, null, 'terminal ' . $cred['terminal'] );
		return array(
			'token'    => (string) $token,
			'expiry'   => $cred['expiry_month'] ? sprintf( '%02d/%04d', $cred['expiry_month'], $cred['expiry_year'] ) : '',
			'terminal' => $cred['terminal'],
			'last4'    => $cred['last4'],
		);
	}
}
