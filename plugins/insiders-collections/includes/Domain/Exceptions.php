<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Exception queue (§12 תור חריגים). Anything the system cannot decide safely
 * lands here with a reason, severity and owner — never a silent default.
 * Keys make opening idempotent: the same webhook delivered five times opens one row.
 */
final class Exceptions {

	public const LABELS = array(
		'event_without_customer'  => 'אירוע ללא לקוח מזוהה',
		'charge_without_cycle'    => 'חיוב ללא מחזור מזוהה',
		'payment_without_debt'    => 'תקבול ללא חוב מתאים',
		'overpayment'             => 'תשלום עודף',
		'integration_failure'     => 'כשל חיבור',
		'send_unknown'            => 'שליחה במצב לא ידוע',
		'card_task_overdue'       => 'משימת כרטיס באיחור',
		'payment_claimed'         => 'לקוח טוען ששילם',
		'identity_conflict'       => 'זהות לא חד־משמעית',
		'unverified_event'        => 'אירוע שלא אומת',
		'balance_mismatch'        => 'אי־התאמה ביתרה',
		'refund_or_chargeback'    => 'החזר או הכחשה',
		'sent_after_settlement'   => 'פנייה אחרי הסדרה',
		'account_opened_after_charge' => 'נפתח חשבון אחרי הפעלת חיוב',
		'reconcile_stale'         => 'התאמה כספית לא עדכנית',
		'paid_per_crm'            => 'שולם לפי פייפדרייב, לא התקבל כאן',
	);

	public static function open( string $key, string $type, string $severity, string $summary, array $opts = array() ): int {
		$existing = Db::row( 'SELECT id, status FROM ' . Db::t( 'exceptions' ) . ' WHERE exception_key = %s', $key );
		if ( $existing ) {
			if ( 'open' === $existing['status'] && isset( $opts['details'] ) ) {
				Db::update( 'exceptions', array( 'details' => wp_json_encode( $opts['details'] ) ), array( 'id' => (int) $existing['id'] ) );
			}
			return (int) $existing['id'];
		}
		try {
			$id = Db::insert(
				'exceptions',
				array(
					'exception_key'  => $key,
					'type'           => $type,
					'severity'       => $severity,
					'owner_id'       => $opts['owner_id'] ?? ( (int) Settings::get( 'fallback_owner_id', 0 ) ?: null ),
					'entity_type'    => $opts['entity_type'] ?? null,
					'entity_id'      => $opts['entity_id'] ?? null,
					'customer_id'    => $opts['customer_id'] ?? null,
					'inbox_event_id' => $opts['inbox_event_id'] ?? null,
					'summary'        => mb_substr( $summary, 0, 500 ),
					'details'        => isset( $opts['details'] ) ? wp_json_encode( $opts['details'] ) : null,
					'status'         => 'open',
					'opened_at'      => Clock::utc(),
				)
			);
		} catch ( \Insiders\Collections\Support\DbError $e ) {
			if ( $e->duplicate ) {
				return (int) Db::value( 'SELECT id FROM ' . Db::t( 'exceptions' ) . ' WHERE exception_key = %s', $key );
			}
			throw $e;
		}
		if ( in_array( $severity, array( 'high', 'critical' ), true ) ) {
			Tasks::alert( 'exception:' . $id, self::LABELS[ $type ] ?? $type, $summary );
		}
		return $id;
	}

	public static function resolve( int $id, string $resolution ): void {
		if ( '' === trim( $resolution ) ) {
			throw new DomainError( 'resolution_required', 'יש לתעד את הפתרון והאסמכתה', 400, array( 'resolution' => 'חובה' ) );
		}
		Db::update(
			'exceptions',
			array(
				'status'      => 'resolved',
				'resolution'  => mb_substr( $resolution, 0, 1000 ),
				'resolved_by' => get_current_user_id() ?: null,
				'resolved_at' => Clock::utc(),
			),
			array( 'id' => $id )
		);
		Audit::log( 'exception.resolve', 'exception', $id, null, null, $resolution );
	}

	public static function resolve_by_key( string $key, string $resolution ): void {
		$id = (int) Db::value( 'SELECT id FROM ' . Db::t( 'exceptions' ) . " WHERE exception_key = %s AND status = 'open'", $key );
		if ( $id ) {
			self::resolve( $id, $resolution );
		}
	}
}
