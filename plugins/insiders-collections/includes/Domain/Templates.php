<?php
namespace Insiders\Collections\Domain;

use Insiders\Collections\Support\Audit;
use Insiders\Collections\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Message templates (§11), versioned. Wording rules baked in:
 * - customer-facing text talks about "תשלום", never "חוב" or "גבייה". WhatsApp's
 *   Business Policy (§4, updated 2026-09-23) prohibits use for "debt collection";
 *   reminders about a customer's own payment are Utility "account updates".
 * - every amount, date and link is injected by the system; an empty variable blocks the send.
 * - "מה שסיכמנו" only with a documented, approved promise (enforced in Messaging).
 */
final class Templates {
	private const OPTION = 'icol_templates';

	public static function defaults(): array {
		$t = static fn( string $key, string $kind, string $channel, string $body, array $o = array() ) => array_merge(
			array(
				'key'                 => $key,
				'version'             => 1,
				'kind'                => $kind,
				'channel'             => $channel,
				'provider_name'       => '',
				'params'              => array(),
				'requires_link'       => false,
				'counts_toward_quota' => 'collection_reminder' === $kind,
				'approval_state'      => 'draft',
				'language'            => 'he',
				'use_when'            => '',
				'block_when'          => '',
				'body'                => $body,
				'updated_at'          => null,
			),
			$o
		);
		return array(
			'payment_failed_link'   => $t( 'payment_failed_link', 'collection_reminder', 'whatsapp_template', "{{greeting}} 🙂 כאן צוות INSIDERS.\nהתשלום עבור {{item}} בסך {{amount}} ₪ לא עבר.\nאפשר להסדיר אותו בקישור המאובטח: {{link}}\nאם צריך עזרה, אפשר לכתוב לנו כאן.", array( 'requires_link' => true, 'params' => array( 'greeting', 'item', 'amount', 'link' ), 'use_when' => 'הקישור מסדיר את התשלום בפועל, והמסלול הנפרד אושר לפריט', 'block_when' => 'פריט מחזורי שטרנזילה עדיין מנסה לגבות' ) ),
			'payment_failed_reply'  => $t( 'payment_failed_reply', 'collection_reminder', 'whatsapp_template', "{{greeting}} 🙂 כאן צוות INSIDERS.\nהתשלום עבור {{item}} בסך {{amount}} ₪ לא עבר.\nנשמח לעזור להסדיר אותו. אפשר להשיב כאן ונחזור אליך.", array( 'params' => array( 'greeting', 'item', 'amount' ), 'use_when' => 'אין מסלול קישור מאומת לפריט' ) ),
			'card_fix_email'        => $t( 'card_fix_email', 'collection_reminder', 'whatsapp_template', "{{greeting}}, כאן צוות INSIDERS.\nהתשלום עבור {{item}} לא עבר בגלל בעיה בכרטיס האשראי.\nטרנזילה, חברת הסליקה שלנו, שלחה ל־{{email_masked}} קישור מאובטח לעדכון הכרטיס. אחרי העדכון החיוב יתבצע שוב אוטומטית.\nלא הגיע מייל? אפשר לכתוב לנו כאן.", array( 'params' => array( 'greeting', 'item', 'email_masked' ), 'use_when' => 'כשל מסוג כרטיס, מייל תיקון הכרטיס של טרנזילה פעיל וכתובת המייל ידועה', 'block_when' => 'יותר מתשלום פתוח אחד בהוראה (ראו card_fix_email_multi)' ) ),
			'card_fix_email_multi'  => $t( 'card_fix_email_multi', 'collection_reminder', 'whatsapp_template', "{{greeting}}, כאן צוות INSIDERS.\nכמה תשלומים בהוראת הקבע לא עברו בגלל בעיה בכרטיס האשראי: {{items_count}} תשלומים, בסך כולל של {{amount}} ₪.\nטרנזילה שלחה ל־{{email_masked}} קישור מאובטח לעדכון הכרטיס. אחרי העדכון התשלומים האלה ייגבו שוב אוטומטית.\nאם צריך לבדוק משהו לפני כן, אפשר לכתוב לנו כאן.", array( 'params' => array( 'greeting', 'items_count', 'amount', 'email_masked' ), 'use_when' => 'כמה פריטים פתוחים באותה הוראה, הלקוח רואה את הסכום הכולל לפני העדכון' ) ),
			'clarify_before_charge' => $t( 'clarify_before_charge', 'collection_reminder', 'whatsapp_template', "{{greeting}}, כאן צוות INSIDERS. חוזרים אליך לגבי פתיחת חשבון המסחר במסגרת תוכנית הליווי.\nלפי הרישום אצלנו התהליך עדיין לא הושלם. כבר פתחת חשבון, או שיש משהו שצריך לבדוק יחד?", array( 'params' => array( 'greeting' ), 'use_when' => 'שלב בירור לפני דרישת תשלום' ) ),
			'program_charge_link'   => $t( 'program_charge_link', 'collection_reminder', 'whatsapp_template', "{{greeting}}, כאן צוות INSIDERS. חוזרים אליך לגבי תוכנית הליווי.\nלפי הרישום אצלנו עדיין לא נפתח חשבון מסחר, והגיע מועד התשלום על התוכנית לפי הסכם ההצטרפות. הסכום לתשלום הוא {{amount}} ₪.\nאפשר להסדיר בקישור המאובטח: {{link}}\nאם כבר פתחת חשבון או שיש משהו לבדוק, אפשר לכתוב לנו כאן.", array( 'requires_link' => true, 'params' => array( 'greeting', 'amount', 'link' ) ) ),
			'program_charge_reply'  => $t( 'program_charge_reply', 'collection_reminder', 'whatsapp_template', "{{greeting}}, כאן צוות INSIDERS. חוזרים אליך לגבי תוכנית הליווי.\nלפי הרישום אצלנו עדיין לא נפתח חשבון מסחר, והגיע מועד התשלום על התוכנית לפי הסכם ההצטרפות. הסכום לתשלום הוא {{amount}} ₪.\nאפשר להשיב כאן ונשלח את פרטי ההסדרה. אם כבר פתחת חשבון או שיש משהו לבדוק, נשמח לשמוע.", array( 'params' => array( 'greeting', 'amount' ) ) ),
			'reminder_link'         => $t( 'reminder_link', 'collection_reminder', 'whatsapp_template', "{{greeting}}, מזכירים לגבי התשלום עבור {{item}} שמופיע אצלנו כפתוח, בסך {{balance}} ₪.\nזה הקישור להסדרה: {{link}}\nאם משהו הסתבך, אפשר לכתוב לנו ונעזור.", array( 'requires_link' => true, 'params' => array( 'greeting', 'item', 'balance', 'link' ) ) ),
			'reminder_reply'        => $t( 'reminder_reply', 'collection_reminder', 'whatsapp_template', "{{greeting}}, מזכירים לגבי התשלום עבור {{item}} שמופיע אצלנו כפתוח, בסך {{balance}} ₪.\nאם משהו הסתבך, אפשר לכתוב לנו כאן ונעזור.", array( 'params' => array( 'greeting', 'item', 'balance' ) ) ),
			'promise_followup'      => $t( 'promise_followup', 'collection_reminder', 'whatsapp_template', "{{greeting}}, חוזרים אליך בהמשך למה שסיכמנו לגבי התשלום ב־{{promise_date}}.\nהיתרה בסך {{balance}} ₪ עדיין מופיעה אצלנו כפתוחה. צריך עזרה בהסדרה?", array( 'params' => array( 'greeting', 'promise_date', 'balance' ), 'use_when' => 'רק עם הבטחה מתועדת ומאושרת שהגיע מועדה ולא נקלט תשלום' ) ),
			'ack_paid'              => $t( 'ack_paid', 'service_reply', 'whatsapp_session', "תודה על העדכון. נעצור בינתיים את התזכורות ונבדוק שהתשלום נקלט.\nאם יש אסמכתה, אפשר לצרף אותה כאן כדי לעזור לנו לאתר אותו." ),
			'payment_verified'      => $t( 'payment_verified', 'payment_confirmation', 'whatsapp_template', "{{greeting}}, התשלום בסך {{paid}} ₪ התקבל. תודה!\n{{status_line}}", array( 'params' => array( 'greeting', 'paid', 'status_line' ) ) ),
			'handoff'               => $t( 'handoff', 'service_reply', 'whatsapp_session', "העברנו את הבקשה לנציג שמטפל בנושא, כדי לבדוק איתך את האפשרויות.\nבינתיים התזכורות האוטומטיות מושהות." ),
			'bot_answer'            => $t( 'bot_answer', 'service_reply', 'whatsapp_session', "זו הודעה אוטומטית ממערכת התשלומים של INSIDERS. נציג מהצוות יחזור אליך כאן, ובינתיים התזכורות מושהות." ),
			'stop_ack'              => $t( 'stop_ack', 'service_reply', 'whatsapp_session', "קיבלנו. לא נשלח יותר תזכורות אוטומטיות בוואטסאפ." ),
			'internal_card_alert'   => $t( 'internal_card_alert', 'internal', 'internal', "התקבל תשלום של {{amount}} ₪ מ־{{customer}}.\nהוראת קבע {{sto_id}} במסוף {{terminal}}: נדרש לבדוק ולעדכן את אמצעי התשלום לחיובים הבאים.\nאחראי: {{assignee}}. מועד יעד: {{due}}. {{task_link}}", array( 'counts_toward_quota' => false, 'approval_state' => 'approved' ) ),
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = self::defaults();
		foreach ( (array) $stored as $key => $tpl ) {
			if ( isset( $out[ $key ] ) && is_array( $tpl ) ) {
				$out[ $key ] = array_merge( $out[ $key ], $tpl );
			}
		}
		return $out;
	}

	public static function get( string $key ): ?array {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Editing the wording creates a new version and resets approval: a changed
	 * text is not the text Meta approved (§11 last paragraph).
	 */
	public static function save( string $key, array $changes ): array {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			throw new DomainError( 'not_found', 'תבנית לא מוכרת', 404 );
		}
		$cur     = $all[ $key ];
		$allowed = array( 'body', 'provider_name', 'params', 'approval_state', 'use_when', 'block_when' );
		$next    = $cur;
		foreach ( $allowed as $f ) {
			if ( array_key_exists( $f, $changes ) ) {
				$next[ $f ] = $changes[ $f ];
			}
		}
		if ( $next['body'] !== $cur['body'] || $next['provider_name'] !== $cur['provider_name'] || $next['params'] !== $cur['params'] ) {
			$next['version']        = (int) $cur['version'] + 1;
			$next['approval_state'] = 'internal' === $cur['channel'] ? 'approved' : 'draft';
		}
		if ( ! in_array( $next['approval_state'], array( 'draft', 'submitted', 'approved', 'rejected' ), true ) ) {
			throw new DomainError( 'invalid', 'מצב אישור לא תקין', 400 );
		}
		$problems = self::lint( (string) $next['body'], $next['kind'] );
		if ( $problems ) {
			throw new DomainError( 'template_rules', 'הנוסח מפר את כללי ההודעות: ' . implode( ' · ', $problems ), 422 );
		}
		$next['updated_at'] = Clock::utc();
		$stored             = (array) get_option( self::OPTION, array() );
		$stored[ $key ]     = $next;
		update_option( self::OPTION, $stored, false );
		Audit::log( 'template.save', 'template', 0, array( 'key' => $key, 'version' => $cur['version'] ), array( 'key' => $key, 'version' => $next['version'], 'state' => $next['approval_state'] ), '' );
		return $next;
	}

	/** Tone rules from §9 that a machine can check. */
	public static function lint( string $body, string $kind ): array {
		$p = array();
		if ( preg_match( '/!{2,}/u', $body ) ) {
			$p[] = 'סימני קריאה מרובים';
		}
		if ( preg_match( '/\x{2014}/u', $body ) ) {
			$p[] = 'מקף ארוך';
		}
		// Hebrew prefixes (ו/ה/ב/ל/מ/ש/כ) attach to the word, so \b is not enough; "חובה" and "רחוב" must pass.
		if ( 'internal' !== $kind && preg_match( '/(?<!\p{L})[והבלמשכ]{0,2}(חוב|חובות|גבייה|גביה|פיגור|מתחמק|מתעלם|הוצאה לפועל|עורך דין|תביעה)(?!\p{L})/u', $body ) ) {
			$p[] = 'מילים אסורות בהודעה ללקוח (חוב/גבייה/איום)';
		}
		preg_match_all( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $body, $m );
		if ( count( $m[0] ) > 1 ) {
			$p[] = 'יותר מאימוג׳י אחד';
		}
		return $p;
	}

	/** Render with injected values. Returns [text, missing_vars]. */
	public static function render( array $tpl, array $vars ): array {
		$missing = array();
		$text    = preg_replace_callback(
			'/\{\{\s*([a-z_]+)\s*\}\}/',
			static function ( $m ) use ( $vars, &$missing ) {
				$v = $vars[ $m[1] ] ?? null;
				if ( null === $v || '' === (string) $v ) {
					$missing[] = $m[1];
					return '';
				}
				return (string) $v;
			},
			(string) $tpl['body']
		);
		return array( $text, array_values( array_unique( $missing ) ) );
	}

	public static function greeting( string $first_name ): string {
		return '' !== $first_name ? 'היי ' . $first_name : 'היי';
	}
}
