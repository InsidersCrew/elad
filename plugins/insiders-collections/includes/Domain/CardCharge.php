<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Engine\Scheduler;
use Insiders\Collections\Integrations\RevenueDashboard\FinanceDashboard;
use Insiders\Collections\Integrations\Tranzila\Client as Tranzila;
use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Crypto;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The program charge from the card the student paid the registration fee with.
 *
 * Never silent: once the collections officer approves the charge, the student gets a notice
 * naming the date, the card's last digits and the amount; the charge runs N business days later,
 * only if nothing changed (no reply, no claim of an opened account, no payment). A declined card
 * falls back to the payment link. An unanswered call is never retried: a person checks Tranzila.
 *
 * Tranzila (2026-10-18): tokens are shared between the terminals and charged on the token terminal
 * (no ID or CVV). Apple Pay tokens are reset by Apple about every two weeks, so an older one is not used.
 */
final class CardCharge {

	public const ACTION = 'card_charge';

	public static function enabled(): bool {
		return Settings::on( 'cap_card_charge' ) && Crypto::available() && Tranzila::configured();
	}

	/** A charge still waiting for its date. While it runs, the fallback to a link must be free to plan. */
	public static function pending( int $case_id ): bool {
		return (bool) Db::value( 'SELECT id FROM ' . Db::t( 'scheduled_actions' ) . " WHERE case_id = %d AND type = %s AND state = 'pending'", $case_id, self::ACTION );
	}

	/**
	 * The registration-fee card for this case, or why there is none.
	 *
	 * @return array{card:?array,reason:string}
	 */
	public static function find_card( int $case_id, string $charge_date ): array {
		$case = Workflow::get( $case_id );
		$agr  = $case ? Journey::agreement( $case ) : null;
		$cust = $case ? Customers::get( (int) $case['customer_id'] ) : null;
		if ( ! $agr || ! $cust ) {
			return array( 'card' => null, 'reason' => 'אין הסכם או לקוח' );
		}
		if ( ! empty( $agr['no_registration_fee'] ) ) {
			return array( 'card' => null, 'reason' => 'הצטרף בלי דמי רישום, אין כרטיס שמור' );
		}
		$signed = $agr['signed_at'] ?: ( $agr['joined_at'] ?: null );
		if ( ! $signed || empty( $cust['phone_e164'] ) ) {
			return array( 'card' => null, 'reason' => 'אין תאריך הסכם או טלפון לחיפוש העסקה' );
		}
		$from = gmdate( 'Y-m-d', strtotime( $signed . ' UTC' ) - 10 * DAY_IN_SECONDS );
		$to   = min( Clock::today(), gmdate( 'Y-m-d', strtotime( $signed . ' UTC' ) + 40 * DAY_IN_SECONDS ) );
		$res  = Tranzila::card_payments_by_phone( (string) Settings::get( 'tranzila_registration_terminal', 'insiders' ), (string) $cust['phone_e164'], $from, $to );
		if ( ! $res['ok'] ) {
			return array( 'card' => null, 'reason' => 'דוח העסקאות לא זמין: ' . $res['error'] );
		}
		$fee        = (int) ( Pricing::row_for( $signed )['registration'] ?? 0 );
		$apple_days = max( 0, (int) Settings::get( 'apple_pay_token_days', 14 ) );
		$why        = 'לא נמצא תשלום בכרטיס סביב תאריך ההסכם';
		$best       = null;
		foreach ( $res['rows'] as $r ) {
			[ $mm, $yy ] = CardTasks::parse_expiry( (string) $r['expiry'] );
			if ( ! $mm || sprintf( '%04d-%02d', $yy, $mm ) < substr( $charge_date, 0, 7 ) ) {
				$why = 'הכרטיס ששימש לדמי הרישום פג תוקף לפני מועד החיוב';
				continue;
			}
			$paid_on = substr( (string) $r['date'], 0, 10 );
			if ( 'APPLE_PAY' === $r['broker'] && strtotime( $charge_date . ' UTC' ) - strtotime( $paid_on . ' UTC' ) > $apple_days * DAY_IN_SECONDS ) {
				$why = 'דמי הרישום שולמו ב-Apple Pay, והטוקן של אפל מתאפס בערך כל שבועיים';
				continue;
			}
			$card = array( 'token' => $r['token'], 'month' => $mm, 'year' => $yy, 'last4' => substr( $r['token'], -4 ), 'index' => $r['index'], 'paid_on' => $paid_on, 'amount_minor' => (int) $r['amount_minor'] );
			if ( $fee && (int) $r['amount_minor'] === $fee ) {
				return array( 'card' => $card, 'reason' => '' ); // the registration fee itself
			}
			$best = $best ?? $card;
		}
		return $best ? array( 'card' => $best, 'reason' => '' ) : array( 'card' => null, 'reason' => $why );
	}

	/**
	 * Called when the officer approves the charge. True when the notice went out and the charge is scheduled;
	 * false means the caller takes the payment-link path.
	 */
	public static function plan( int $case_id, int $item_id ): bool {
		if ( ! self::enabled() ) {
			return false;
		}
		$case   = Workflow::get( $case_id );
		$policy = Policy::effective_or_draft();
		$days   = max( 1, (int) Settings::get( 'card_charge_notice_days', 3 ) );
		$date   = Calendar::add_business_days( Clock::today(), $days, $policy );
		$found  = self::find_card( $case_id, $date );
		if ( ! $found['card'] ) {
			Audit::log( 'card_charge.skip', 'case', $case_id, null, null, $found['reason'] );
			self::note( $case_id, 'חיוב מהכרטיס השמור לא אפשרי: ' . $found['reason'] . '. ממשיכים בקישור תשלום.' );
			return false;
		}
		$card = $found['card'];
		$agr  = Journey::agreement( $case );
		$tpl  = Templates::get( 'card_charge_notice' );
		$vars = array(
			'name'        => Templates::name_or_neutral( Customers::greeting_name( Customers::get( (int) $case['customer_id'] ) ) ),
			'charge_date' => Journey::date_he( $date ),
			'card_digits' => $card['last4'], // variable names are letters only: {{last4}} would not render
			'amount_text' => Pricing::amount_text( $agr ),
		);
		[ $text, $missing ] = Templates::render( $tpl, $vars );
		$guard = SendGuard::check( $case_id, array( 'template' => $tpl, 'is_reminder' => false ) );
		// Same hard blocks as a reply with payment details; setup gaps block only a live send (display-only records a simulation).
		$hard  = array_filter( $guard['blocking'], static fn( $b ) => in_array( $b['code'], array( 'opted_out', 'identity', 'shared_phone', 'no_phone', 'conversation_owner', 'delivery_unknown', 'no_permission' ), true ) || ( 'setup' === $b['scope'] && 'live' === $guard['mode'] ) );
		if ( $missing || $hard ) {
			// No notice, no charge: the card is never charged without telling the student first.
			self::note( $case_id, 'הודעת החיוב מהכרטיס לא יכולה לצאת (' . implode( ', ', $missing ?: array_column( $hard, 'message' ) ) . '). ממשיכים בקישור תשלום.' );
			return false;
		}
		$cred = Db::insert(
			'card_credentials',
			array(
				'terminal'          => (string) Settings::get( 'tranzila_registration_terminal', 'insiders' ),
				'encrypted_token'   => Crypto::encrypt( $card['token'] ),
				'expiry_month'      => $card['month'],
				'expiry_year'       => $card['year'],
				'last4'             => $card['last4'],
				'source_payment_id' => null,
				'fingerprint'       => Crypto::fingerprint( 'registration|' . $case_id . '|' . $card['index'] . '|' . microtime( true ) ),
				'created_at'        => Clock::utc(),
			)
		);
		Scheduler::cancel_for_case( $case_id, 'card_charge_planned' );
		$message_id = Messaging::record_outbound( $case, $tpl, $text, $guard['mode'], 'system', null, $vars );
		Scheduler::schedule(
			self::ACTION,
			Clock::local_to_ts( $date, '10:00' ),
			'card_charge:' . $case_id . ':' . $item_id,
			$case_id,
			(int) $case['customer_id'],
			array( 'item_id' => $item_id, 'credential_id' => $cred, 'notice_message_id' => $message_id, 'notice_mode' => $guard['mode'], 'notice_at' => Clock::utc(), 'source_index' => $card['index'] ),
			null
		);
		Db::update( 'cases', array( 'next_action_type' => self::ACTION, 'next_action_at' => Clock::utc( Clock::local_to_ts( $date, '10:00' ) ) ), array( 'id' => $case_id ) );
		Audit::log( 'card_charge.plan', 'case', $case_id, null, array( 'date' => $date, 'last4' => $card['last4'], 'source_index' => $card['index'] ), 'הודעה מקדימה נשלחה, החיוב מתוזמן' );
		return true;
	}

	/** The scheduled charge. */
	public static function execute( array $action ): string {
		$p       = (array) json_decode( (string) $action['payload'], true );
		$case_id = (int) $action['case_id'];
		$case    = Workflow::get( $case_id );
		$item    = Ledger::item( (int) ( $p['item_id'] ?? 0 ) );
		if ( ! $case || ! $item || 'closed' === $case['workflow_state'] || (int) $item['cached_balance_minor'] <= 0 ) {
			return 'done:nothing_due';
		}
		$stop = array();
		if ( (int) $case['dispute_open'] ) {
			$stop[] = 'מחלוקת פתוחה';
		}
		if ( (int) $case['claims_account_opened'] ) {
			$stop[] = 'התלמיד כתב שפתח חשבון';
		}
		if ( ! in_array( $case['workflow_state'], Workflow::SENDABLE, true ) ) {
			$stop[] = 'התיק ' . Workflow::label( $case['workflow_state'] );
		}
		$replied = (int) Db::value( 'SELECT COUNT(*) FROM ' . Db::t( 'messages' ) . " WHERE case_id = %d AND direction = 'in' AND occurred_at > %s", $case_id, (string) ( $p['notice_at'] ?? $action['created_at'] ) );
		if ( $replied ) {
			$stop[] = 'התלמיד הגיב אחרי הודעת החיוב';
		}
		$notice = Db::value( 'SELECT delivery_state FROM ' . Db::t( 'messages' ) . ' WHERE id = %d', (int) ( $p['notice_message_id'] ?? 0 ) );
		if ( in_array( $notice, array( 'failed', 'cancelled' ), true ) ) {
			$stop[] = 'הודעת החיוב לא הגיעה לתלמיד';
		}
		$agr  = Journey::agreement( $case );
		$cust = Customers::get( (int) $case['customer_id'] );
		if ( $agr && $agr['pipedrive_deal_id'] && $cust['pipedrive_person_id'] && FinanceDashboard::available() && FinanceDashboard::deal_resolution( (int) $cust['pipedrive_person_id'], (int) $agr['pipedrive_deal_id'] ) ) {
			$stop[] = 'לפי דשבורד ההכנסות התלמיד פתח חשבון או שילם';
		}
		if ( $stop ) {
			Tasks::open( 'card_charge_hold:' . $case_id, 'reply_review', array( 'case_id' => $case_id, 'customer_id' => (int) $case['customer_id'], 'priority' => 'high', 'reason' => 'החיוב מהכרטיס השמור לא בוצע: ' . implode( ' · ', $stop ) . '. לבדוק ולהחליט אם לשלוח קישור תשלום.' ) );
			self::note( $case_id, 'החיוב מהכרטיס השמור נעצר: ' . implode( ' · ', $stop ) );
			self::hold( $case_id, 'החיוב מהכרטיס השמור נעצר, ממתין להחלטה של נציג' );
			return 'cancel:held';
		}
		$cred   = Db::row( 'SELECT * FROM ' . Db::t( 'card_credentials' ) . ' WHERE id = %d', (int) ( $p['credential_id'] ?? 0 ) );
		$token  = $cred ? Crypto::decrypt( (string) $cred['encrypted_token'] ) : null;
		$amount = (int) $item['cached_balance_minor'];
		if ( ! $token ) {
			self::note( $case_id, 'הכרטיס השמור לא נקרא (מפתח ההצפנה?). ממשיכים בקישור תשלום.' );
			self::fallback( $case_id );
			return 'done:no_token';
		}
		// A notice that was only simulated never leads to a real charge, even if the switches changed since.
		if ( Settings::on( 'kill_switch' ) || Settings::on( 'display_only' ) || 'live' !== ( $p['notice_mode'] ?? '' ) ) {
			self::note( $case_id, 'סימולציה: היה מחויב הכרטיס שמסתיים ב-' . $cred['last4'] . ' בסך ' . Money::ils( $amount ) . ' (מצב תצוגה בלבד או מתג עצירה)' );
			return 'done:simulated';
		}
		$terminal = (string) Settings::get( 'tranzila_token_charge_terminal', 'insiderstok' );
		$dedupe   = 'icol-case-' . $case_id . '-item-' . (int) $item['id'];
		$r        = Tranzila::charge_token( $terminal, $token, (int) $cred['expiry_month'], (int) $cred['expiry_year'], $amount, (string) $item['description'], $dedupe );
		Audit::log( 'card_charge.result', 'case', $case_id, null, array( 'outcome' => $r['outcome'], 'response' => $r['response'], 'amount' => $amount, 'last4' => $cred['last4'] ), $r['error'] );
		if ( 'approved' === $r['outcome'] ) {
			$pid = Db::transaction(
				function () use ( $case, $terminal, $r, $amount, $cred, $item ) {
					[ $pid ] = Payments::record(
						array(
							'provider'            => 'tranzila',
							'terminal'            => $terminal,
							'transaction_id'      => $r['transaction_id'],
							'customer_id'         => (int) $case['customer_id'],
							'amount_minor'        => $amount,
							'currency'            => 'ILS',
							'origin'              => 'card_charge',
							'payment_method'      => 'card',
							'has_reusable_token'  => 1,
							'card_last4'          => $r['last4'] ?: $cred['last4'],
							'verification_source' => 'api',
							'evidence_ref'        => 'tranzila:' . $terminal . ':' . $r['transaction_id'] . ( $r['auth'] ? ':auth ' . $r['auth'] : '' ),
						)
					);
					if ( Ledger::payment_unallocated( $pid ) >= $amount ) {
						Ledger::allocate( $pid, (int) $item['id'], $amount, 'חיוב מהכרטיס ששימש לדמי הרישום' );
					}
					return $pid;
				}
			);
			Payments::after_payment( (int) $pid );
			return 'done:charged';
		}
		if ( 'unknown' === $r['outcome'] ) {
			Exceptions::open( 'card_charge_unknown:' . $case_id, 'integration_failure', 'high', 'חיוב מהכרטיס השמור לא קיבל תשובה מטרנזילה (' . $r['error'] . '). לבדוק בממשק טרנזילה אם החיוב בוצע, לפני כל פעולה נוספת.', array( 'case_id' => $case_id ) );
			self::note( $case_id, 'החיוב מהכרטיס לא קיבל תשובה. לא נשלח קישור עד לבדיקה בטרנזילה.' );
			self::hold( $case_id, 'חיוב מהכרטיס בלי תשובה מטרנזילה, לבדוק לפני כל פעולה' );
			return 'done:unknown';
		}
		self::note( $case_id, 'החיוב מהכרטיס שמסתיים ב-' . $cred['last4'] . ' לא עבר (' . ( $r['response'] ?: $r['error'] ) . '). ממשיכים בקישור תשלום.' );
		self::fallback( $case_id );
		return 'done:declined';
	}

	/**
	 * To a person: nothing automatic follows (the hourly sync would otherwise move the case
	 * to the payment-link stage and send a link while the charge is unresolved).
	 */
	private static function hold( int $case_id, string $reason ): void {
		$case = Workflow::get( $case_id );
		if ( $case && 'human_review' !== $case['workflow_state'] && 'closed' !== $case['workflow_state'] ) {
			Workflow::transition( $case_id, 'human_review', $reason, null, array(), 'card_charge' );
		}
	}

	/** A line in the case's history (the audit trail the case screen shows). */
	private static function note( int $case_id, string $text ): void {
		Audit::log( 'case.card_charge', 'case', $case_id, null, null, $text );
	}

	/** Back to the payment-link path the approval would have taken. */
	private static function fallback( int $case_id ): void {
		$case = Workflow::get( $case_id );
		$agr  = $case ? Journey::agreement( $case ) : null;
		if ( ! $case || ! $agr ) {
			return;
		}
		Db::update( 'cases', array( 'next_action_type' => null, 'next_action_at' => null ), array( 'id' => $case_id ) );
		if ( 'commitment' === $case['phase'] && (string) $agr['account_open_deadline'] >= Clock::today() ) {
			Journey::plan_next( $case_id );
		} else {
			Journey::to_charge( $case_id );
		}
	}
}
