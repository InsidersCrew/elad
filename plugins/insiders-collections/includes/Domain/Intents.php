<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Integrations\Ai\Classifier;
use Insiders\Collections\Support\Clock;
use Insiders\Collections\Support\Db;
use Insiders\Collections\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Inbound intent routing (§10). Deterministic rules run first and alone decide
 * the safety-critical intents (stop, wrong number, dispute, "paid", "opened an
 * account") — none of those may depend on a model being up. The model, when
 * enabled, adds a suggestion for the rep; it never moves money or approves anything.
 */
final class Intents {

	/**
	 * Ordered: the first match wins, most protective first.
	 * \b does not work next to Hebrew letters (PCRE without UCP treats them as
	 * non-word), so word edges use (?<!\p{L}) / (?!\p{L}).
	 */
	private const RULES = array(
		'stop'           => '/(תפסיקו|להפסיק|הפסיקו|הסר(ו)?\s*אותי|הסירו|תורידו אותי|אל תשלחו|לא לשלוח|אל תפנו|\bstop\b|\bunsubscribe\b)/iu',
		'wrong_number'   => '/(מספר שגוי|טעות במספר|לא אני|לא מכיר|לא מכירה|מי זה|מי זאת|wrong number)/iu',
		'dispute'        => '/(לא חייב|לא חייבת|לא מגיע לכם|לבטל|ביטול|מחלוקת|לא הסכמתי|לא חתמתי|עורך דין|עו"ד|תלונה|הונאה|רמאות|גניבה)/iu',
		'human'          => '/(נציג|נציגה|בן אדם|אדם אמיתי|לדבר עם|תתקשרו|תחזרו אליי|שיחה טלפונית|טלפון אליי|מישהו מהצוות)/iu',
		'claims_paid'    => '/(שילמתי|כבר שילמתי|העברתי|שולם|הסדרתי|ביצעתי תשלום|עשיתי העברה|אסמכתא|אסמכתה|קבלה)/iu',
		'opened_account' => '/(פתחתי חשבון|כבר פתחתי|יש לי חשבון|החשבון נפתח|פתחתי את החשבון)/iu',
		'wants_to_open'  => '/(רוצה לפתוח|אפשר לפתוח|איך פותחים|לפתוח עכשיו|עזרה בפתיחה)/iu',
		'hardship'       => '/(פריסה|בתשלומים|קשה לי|אין לי כסף|מצב כלכלי|לא יכול לשלם|לא יכולה לשלם|מובטל|מובטלת|הנחה)/iu',
		'promise'        => '/(אשלם|אסדיר|אעביר|בתחילת החודש|בסוף החודש|בשבוע הבא|ביום (ראשון|שני|שלישי|רביעי|חמישי|שישי)|(?<!\p{L})ב(ראשון|שני|שלישי|רביעי|חמישי)(?!\p{L})|עד ה?-?\s?\d{1,2}|ב-?\s?\d{1,2}\s*(ל|\/)\s*\d{1,2}|(?<!\p{L})מחר(?!\p{L})|אחרי המשכורת|כשתיכנס המשכורת)/iu',
		'bot_question'   => '/(בוט|רובוט|אוטומטי|מערכת אוטומטית|אדם או מחשב|ai\b)/iu',
		'link_request'   => '/(קישור|לינק|link|איך משלמים|איפה משלמים|איך אפשר לשלם|לשלם עכשיו)/iu',
	);

	public static function classify( string $text, string $type ): array {
		if ( in_array( $type, array( 'audio', 'voice', 'ptt' ), true ) ) {
			return array( 'intent' => 'voice', 'source' => 'rules', 'confidence' => 'rule' );
		}
		if ( in_array( $type, array( 'image', 'document', 'video', 'sticker' ), true ) && '' === trim( $text ) ) {
			return array( 'intent' => 'media', 'source' => 'rules', 'confidence' => 'rule' );
		}
		foreach ( self::RULES as $intent => $re ) {
			if ( preg_match( $re, $text ) ) {
				return array( 'intent' => $intent, 'source' => 'rules', 'confidence' => 'rule' );
			}
		}
		return array( 'intent' => 'unclear', 'source' => 'rules', 'confidence' => 'none' );
	}

	/** Applies the §10 table to every open case of the customer. */
	public static function apply( int $customer_id, int $message_id, array $intent, array $cases, array $msg ): void {
		$name   = $intent['intent'];
		$sendable_or_waiting = array_values( array_filter( $cases, static fn( $c ) => 'closed' !== $c['workflow_state'] ) );
		$first  = $sendable_or_waiting[0] ?? null;
		$to_review = static function ( array $c, string $reason ) {
			if ( Workflow::can( $c['workflow_state'], 'human_review' ) ) {
				Workflow::transition( (int) $c['id'], 'human_review', $reason, null, array(), 'inbound' );
			}
		};

		switch ( $name ) {
			case 'stop':
				// Channel-level block across all cases (AT16). The debt is untouched; no other channel is auto-enabled.
				Customers::set_permission( $customer_id, 'whatsapp', false, 'customer_request:message:' . $message_id );
				foreach ( $sendable_or_waiting as $c ) {
					$to_review( $c, 'הלקוח ביקש להפסיק הודעות בוואטסאפ' );
				}
				if ( $first ) {
					Messaging::service_reply( (int) $first['id'], 'stop_ack' );
				}
				break;

			case 'wrong_number':
				Customers::mark_wrong_number( $customer_id, 'message:' . $message_id );
				foreach ( $sendable_or_waiting as $c ) {
					$to_review( $c, 'דווח מספר שגוי, נדרשת בדיקת זהות' );
				}
				Exceptions::open( 'identity:' . $customer_id, 'identity_conflict', 'medium', 'הנמען דיווח שהמספר שגוי', array( 'customer_id' => $customer_id ) );
				break;

			case 'dispute':
				foreach ( $sendable_or_waiting as $c ) {
					Db::update( 'cases', array( 'dispute_open' => 1 ), array( 'id' => (int) $c['id'] ) );
					$to_review( $c, 'הלקוח חולק על החיוב או מבקש ביטול' );
					Tasks::open( 'dispute:' . $c['id'], 'dispute', array( 'case_id' => (int) $c['id'], 'customer_id' => $customer_id, 'reason' => mb_substr( (string) ( $msg['text'] ?? '' ), 0, 300 ), 'priority' => 'high' ) );
				}
				if ( $first ) {
					Messaging::service_reply( (int) $first['id'], 'handoff' );
				}
				break;

			case 'claims_paid':
				// AT14: a claim is never proof. Pause, acknowledge, open verification.
				foreach ( $sendable_or_waiting as $c ) {
					if ( Workflow::can( $c['workflow_state'], 'payment_verification' ) ) {
						Workflow::transition( (int) $c['id'], 'payment_verification', 'הלקוח טוען ששילם', null, array(), 'inbound' );
					}
					Tasks::open( 'verify_payment:' . $c['id'] . ':' . $message_id, 'verify_payment', array( 'case_id' => (int) $c['id'], 'customer_id' => $customer_id, 'reason' => 'טענת תשלום: ' . mb_substr( (string) ( $msg['text'] ?? '' ), 0, 200 ) ) );
				}
				Exceptions::open( 'payment_claimed:' . $message_id, 'payment_claimed', 'medium', 'לקוח טוען ששילם', array( 'customer_id' => $customer_id, 'entity_type' => 'message', 'entity_id' => $message_id ) );
				if ( $first ) {
					Messaging::service_reply( (int) $first['id'], 'ack_paid' );
				}
				break;

			case 'opened_account':
				// AT15: no automatic decision about the debt.
				foreach ( $sendable_or_waiting as $c ) {
					if ( 'non_open_charge' !== $c['source_type'] ) {
						$to_review( $c, 'הלקוח כתב על פתיחת חשבון' );
						continue;
					}
					Db::update( 'cases', array( 'claims_account_opened' => 1 ), array( 'id' => (int) $c['id'] ) );
					$to_review( $c, 'הלקוח טוען שפתח חשבון, עצירה עד בדיקת אחראי' );
					Tasks::open( 'account_opened_claim:' . $c['id'], 'account_opened_claim', array( 'case_id' => (int) $c['id'], 'customer_id' => $customer_id, 'reason' => 'לבדוק סטטוס פתיחת חשבון ולהחליט: המשך, התאמה או סגירה' ) );
				}
				if ( $first ) {
					Messaging::service_reply( (int) $first['id'], 'handoff' );
				}
				break;

			case 'wants_to_open':
				// Opening the account is the outcome the business prefers; route it, don't collect over it.
				foreach ( $sendable_or_waiting as $c ) {
					$to_review( $c, 'הלקוח רוצה לפתוח חשבון, לניתוב לצוות ההרשמה' );
					Tasks::open( 'wants_to_open:' . $c['id'], 'reply_review', array( 'case_id' => (int) $c['id'], 'customer_id' => $customer_id, 'reason' => 'לקוח מבקש לפתוח חשבון, להחליט על ניתוב לסוכן ההרשמה', 'priority' => 'high' ) );
				}
				if ( $first ) {
					Messaging::service_reply( (int) $first['id'], 'handoff' );
				}
				break;

			case 'hardship':
				foreach ( $sendable_or_waiting as $c ) {
					$to_review( $c, 'קושי כספי או בקשת פריסה' );
					Tasks::open( 'hardship:' . $c['id'], 'hardship', array( 'case_id' => (int) $c['id'], 'customer_id' => $customer_id, 'reason' => mb_substr( (string) ( $msg['text'] ?? '' ), 0, 300 ) ) );
				}
				if ( $first ) {
					Messaging::service_reply( (int) $first['id'], 'handoff' );
				}
				break;

			case 'promise':
				// Default: a request waiting for a rep. The accounting due date never moves (§10).
				$date = self::extract_date( (string) ( $msg['text'] ?? '' ), (string) ( $msg['occurred_at'] ?? Clock::utc() ) );
				foreach ( $sendable_or_waiting as $c ) {
					if ( $date ) {
						Promises::request( (int) $c['id'], $date, null, 'customer', $message_id, (string) ( $msg['text'] ?? '' ) );
					} else {
						$to_review( $c, 'בקשת מועד ללא תאריך חד־משמעי' );
						Tasks::open( 'promise_request:' . $c['id'] . ':' . $message_id, 'promise_request', array( 'case_id' => (int) $c['id'], 'reason' => 'הלקוח ציין מועד לא חד־משמעי: ' . mb_substr( (string) ( $msg['text'] ?? '' ), 0, 200 ) ) );
					}
				}
				break;

			case 'bot_question':
				foreach ( $sendable_or_waiting as $c ) {
					$to_review( $c, 'הלקוח שאל אם מדובר במערכת אוטומטית' );
				}
				if ( $first ) {
					Messaging::service_reply( (int) $first['id'], 'bot_answer' );
				}
				break;

			case 'human':
				// AT13: ownership moves to a person; the onboarding agent and collections both step back.
				Db::update( 'customers', array( 'conversation_owner' => 'human', 'conversation_owner_at' => Clock::utc() ), array( 'id' => $customer_id ) );
				\Insiders\Collections\Engine\Outbox::enqueue( 'wati_owner', 'customer', $customer_id, 'owner:' . $customer_id . ':human:' . $message_id, array( 'customer_id' => $customer_id, 'owner' => 'human' ) );
				// fall through to the review routing below
			case 'link_request':
			case 'media':
			case 'voice':
			case 'unclear':
			default:
				foreach ( $sendable_or_waiting as $c ) {
					if ( 'payment_verification' === $c['workflow_state'] && in_array( $name, array( 'media', 'unclear' ), true ) ) {
						// Evidence for an open payment check: attach to the verification, keep the state (§10 תמונה או מסמך).
						Tasks::open( 'verify_evidence:' . $c['id'] . ':' . $message_id, 'verify_payment', array( 'case_id' => (int) $c['id'], 'customer_id' => $customer_id, 'reason' => 'הלקוח צירף תיעוד לבדיקת התשלום (אינו אישור תשלום)' ) );
						continue;
					}
					$to_review( $c, self::LABELS[ $name ] ?? 'הודעה נכנסת' );
					Tasks::open( 'reply:' . $c['id'] . ':' . $message_id, 'reply_review', array( 'case_id' => (int) $c['id'], 'customer_id' => $customer_id, 'reason' => ( self::LABELS[ $name ] ?? 'הודעה נכנסת' ) . ': ' . mb_substr( (string) ( $msg['text'] ?? '' ), 0, 200 ) ) );
				}
				if ( $first && in_array( $name, array( 'human', 'voice' ), true ) ) {
					Messaging::service_reply( (int) $first['id'], 'handoff' );
				}
				break;
		}

		if ( $first && Settings::on( 'cap_ai_suggestions' ) ) {
			// Suggestion only, stored for the rep. Failure here changes nothing above.
			Classifier::suggest_later( (int) $first['id'], $message_id );
		}
	}

	public const LABELS = array(
		'human'        => 'הלקוח ביקש נציג',
		'link_request' => 'הלקוח ביקש קישור לתשלום',
		'media'        => 'התקבלה תמונה או מסמך לבדיקה (אינו אישור תשלום)',
		'voice'        => 'התקבלה הודעה קולית',
		'unclear'      => 'תשובה לא ברורה',
	);

	/**
	 * Absolute dates only: "15/10", "15.10", "ב-15" (this or next month). Relative words
	 * like "בחמישי" resolve against the message date in the business timezone (§6);
	 * anything else returns null and goes to a person.
	 */
	public static function extract_date( string $text, string $message_utc ): ?string {
		$base = Clock::local( Clock::ts( $message_utc ) ?? Clock::now() );
		if ( preg_match( '/(\d{1,2})\s*[\/.]\s*(\d{1,2})(?:\s*[\/.]\s*(\d{2,4}))?/u', $text, $m ) ) {
			$d = (int) $m[1];
			$mo = (int) $m[2];
			$y = isset( $m[3] ) ? (int) $m[3] : (int) $base->format( 'Y' );
			if ( $y < 100 ) {
				$y += 2000;
			}
			if ( checkdate( $mo, $d, $y ) ) {
				$date = sprintf( '%04d-%02d-%02d', $y, $mo, $d );
				if ( ! isset( $m[3] ) && $date < $base->format( 'Y-m-d' ) ) {
					$date = sprintf( '%04d-%02d-%02d', $y + 1, $mo, $d );
				}
				return $date;
			}
			return null;
		}
		$days = array( 'ראשון' => 0, 'שני' => 1, 'שלישי' => 2, 'רביעי' => 3, 'חמישי' => 4, 'שישי' => 5 );
		if ( preg_match( '/(?<!\p{L})ב(?:יום\s)?(ראשון|שני|שלישי|רביעי|חמישי|שישי)(?!\p{L})/u', $text, $m ) && ! preg_match( '/הבא|הבאה/u', $text ) ) {
			$target = $days[ $m[1] ];
			$cur    = (int) $base->format( 'w' );
			$diff   = ( $target - $cur + 7 ) % 7;
			$diff   = 0 === $diff ? 7 : $diff;
			return $base->modify( '+' . $diff . ' days' )->format( 'Y-m-d' );
		}
		if ( preg_match( '/(?<!\p{L})מחר(?!\p{L})/u', $text ) ) {
			return $base->modify( '+1 day' )->format( 'Y-m-d' );
		}
		return null;
	}
}
