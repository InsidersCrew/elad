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
				'buttons'             => array(),
				'meta_category'       => 'collection_reminder' === $kind ? 'utility' : '',
				'body'                => $body,
				'updated_at'          => null,
			),
			$o
		);
		// Quick replies of the journey templates. WhatsApp caps a button at 25 characters;
		// the inbound side matches these exact titles (Journey::button_intent).
		$btn = array( Journey::BTN_OPEN, Journey::BTN_QUESTION, Journey::BTN_DECLINE );
		// The opening message, 45 days out, offers no "will not open" button: too early for a no (INSIDERS, 2026-10-10).
		$btn_open = array( Journey::BTN_OPEN, Journey::BTN_QUESTION );
		// From 30 days out the positive form of the decision: pay instead of opening.
		$btn_pay  = array( Journey::BTN_OPEN, Journey::BTN_QUESTION, Journey::BTN_PAY );
		// The deadline day (and a deadline already passed) is about action: pay, open now, or "already opened".
		$btn_last = array( Journey::BTN_PAY, Journey::BTN_OPEN, Journey::BTN_OPENED );
		return array(
			'payment_failed_link'   => $t( 'payment_failed_link', 'collection_reminder', 'whatsapp_template', "היי {{name}} 🙂 כאן צוות INSIDERS.\nהתשלום עבור {{item}} בסך {{amount}} ₪ לא עבר.\nאפשר להסדיר אותו בקישור המאובטח: {{link}}\nאם צריך עזרה, אפשר לכתוב לנו כאן.", array( 'requires_link' => true, 'params' => array( 'name', 'item', 'amount', 'link' ), 'use_when' => 'הקישור מסדיר את התשלום בפועל, והמסלול הנפרד אושר לפריט', 'block_when' => 'פריט מחזורי שטרנזילה עדיין מנסה לגבות' ) ),
			'payment_failed_reply'  => $t( 'payment_failed_reply', 'collection_reminder', 'whatsapp_template', "היי {{name}} 🙂 כאן צוות INSIDERS.\nהתשלום עבור {{item}} בסך {{amount}} ₪ לא עבר.\nנשמח לעזור להסדיר אותו. אפשר להשיב כאן ונחזור אליך.", array( 'params' => array( 'name', 'item', 'amount' ), 'use_when' => 'אין מסלול קישור מאומת לפריט' ) ),
			'card_fix_email'        => $t( 'card_fix_email', 'collection_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS.\nהתשלום עבור {{item}} לא עבר בגלל בעיה בכרטיס האשראי.\nטרנזילה, חברת הסליקה שלנו, שלחה ל־{{email_masked}} קישור מאובטח לעדכון הכרטיס. אחרי העדכון החיוב יתבצע שוב אוטומטית.\nלא הגיע מייל? אפשר לכתוב לנו כאן.", array( 'params' => array( 'name', 'item', 'email_masked' ), 'use_when' => 'כשל מסוג כרטיס, מייל תיקון הכרטיס של טרנזילה פעיל וכתובת המייל ידועה', 'block_when' => 'יותר מתשלום פתוח אחד בהוראה (ראו card_fix_email_multi)' ) ),
			'card_fix_email_multi'  => $t( 'card_fix_email_multi', 'collection_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS.\nכמה תשלומים בהוראת הקבע לא עברו בגלל בעיה בכרטיס האשראי: {{items_count}} תשלומים, בסך כולל של {{amount}} ₪.\nטרנזילה שלחה ל־{{email_masked}} קישור מאובטח לעדכון הכרטיס. אחרי העדכון התשלומים האלה ייגבו שוב אוטומטית.\nאם צריך לבדוק משהו לפני כן, אפשר לכתוב לנו כאן.", array( 'params' => array( 'name', 'items_count', 'amount', 'email_masked' ), 'use_when' => 'כמה פריטים פתוחים באותה הוראה, הלקוח רואה את הסכום הכולל לפני העדכון' ) ),
			'clarify_before_charge' => $t( 'clarify_before_charge', 'collection_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS. חוזרים אליך לגבי פתיחת חשבון המסחר במסגרת תוכנית הליווי.\nלפי הרישום אצלנו התהליך עדיין לא הושלם. כבר פתחת חשבון, או שיש משהו שצריך לבדוק יחד?", array( 'params' => array( 'name' ), 'use_when' => 'שלב בירור לפני דרישת תשלום' ) ),
			'program_charge_link'   => $t( 'program_charge_link', 'collection_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS. חוזרים אליך לגבי תוכנית הליווי.\nלפי הרישום אצלנו עדיין לא נפתח חשבון מסחר, והגיע מועד התשלום על התוכנית לפי הסכם ההצטרפות. הסכום לתשלום הוא {{amount}} ₪.\nאפשר להסדיר בקישור המאובטח: {{link}}\nאם כבר פתחת חשבון או שיש משהו לבדוק, אפשר לכתוב לנו כאן.", array( 'requires_link' => true, 'params' => array( 'name', 'amount', 'link' ) ) ),
			'program_charge_reply'  => $t( 'program_charge_reply', 'collection_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS. חוזרים אליך לגבי תוכנית הליווי.\nלפי הרישום אצלנו עדיין לא נפתח חשבון מסחר, והגיע מועד התשלום על התוכנית לפי הסכם ההצטרפות. הסכום לתשלום הוא {{amount}} ₪.\nאפשר להשיב כאן ונשלח את פרטי ההסדרה. אם כבר פתחת חשבון או שיש משהו לבדוק, נשמח לשמוע.", array( 'params' => array( 'name', 'amount' ) ) ),
			'reminder_link'         => $t( 'reminder_link', 'collection_reminder', 'whatsapp_template', "היי {{name}}, מזכירים לגבי התשלום עבור {{item}} שמופיע אצלנו כפתוח, בסך {{balance}} ₪.\nזה הקישור להסדרה: {{link}}\nאם משהו הסתבך, אפשר לכתוב לנו ונעזור.", array( 'requires_link' => true, 'params' => array( 'name', 'item', 'balance', 'link' ) ) ),
			'reminder_reply'        => $t( 'reminder_reply', 'collection_reminder', 'whatsapp_template', "היי {{name}}, מזכירים לגבי התשלום עבור {{item}} שמופיע אצלנו כפתוח, בסך {{balance}} ₪.\nאם משהו הסתבך, אפשר לכתוב לנו כאן ונעזור.", array( 'params' => array( 'name', 'item', 'balance' ) ) ),
			'promise_followup'      => $t( 'promise_followup', 'collection_reminder', 'whatsapp_template', "היי {{name}}, חוזרים אליך בהמשך למה שסיכמנו לגבי התשלום ב־{{promise_date}}.\nהיתרה בסך {{balance}} ₪ עדיין מופיעה אצלנו כפתוחה. צריך עזרה בהסדרה?", array( 'params' => array( 'name', 'promise_date', 'balance' ), 'use_when' => 'רק עם הבטחה מתועדת ומאושרת שהגיע מועדה ולא נקלט תשלום' ) ),
			'ack_paid'              => $t( 'ack_paid', 'service_reply', 'whatsapp_session', "תודה על העדכון. נעצור בינתיים את התזכורות ונבדוק שהתשלום נקלט.\nאם יש אסמכתה, אפשר לצרף אותה כאן כדי לעזור לנו לאתר אותו." ),
			'payment_verified'      => $t( 'payment_verified', 'payment_confirmation', 'whatsapp_template', "היי {{name}}, התשלום בסך {{paid}} ₪ התקבל, תודה.\n{{status_line}}\nאם יש שאלה, אפשר לכתוב לנו כאן.", array( 'params' => array( 'name', 'paid', 'status_line' ) ) ),
			'handoff'               => $t( 'handoff', 'service_reply', 'whatsapp_session', "העברנו את הבקשה לנציג שמטפל בנושא, כדי לבדוק איתך את האפשרויות.\nבינתיים התזכורות האוטומטיות מושהות." ),
			'bot_answer'            => $t( 'bot_answer', 'service_reply', 'whatsapp_session', "זו הודעה אוטומטית ממערכת התשלומים של INSIDERS. נציג מהצוות יחזור אליך כאן, ובינתיים התזכורות מושהות." ),
			'stop_ack'              => $t( 'stop_ack', 'service_reply', 'whatsapp_session', "קיבלנו. לא נשלח יותר תזכורות אוטומטיות בוואטסאפ." ),
			// Beginner program, before the deadline (procedure of 2026-10, rewritten gender-neutral).
			// Every amount is {{amount_text}}: "880 ₪ (980 ₪ פחות 100 ₪ דמי הרישום ששולמו)" or "980 ₪".
			'j_intro'               => $t( 'j_intro', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS 🙂\nלפי הרישום אצלנו, פתיחת החשבון במסגרת התוכנית עדיין לא הושלמה. יש זמן עד {{deadline}}, ועדיף לא להשאיר את זה לרגע האחרון.\nהמטרה שלנו היא להגיע איתך לשלב שבו משתמשים בפועל בכלים שלמדת, ואנחנו כאן לאורך כל הדרך.\nמה הכי מתאים עכשיו?", array( 'params' => array( 'name', 'deadline' ), 'buttons' => $btn_open, 'meta_category' => 'marketing', 'counts_toward_quota' => true, 'use_when' => '45 יום לפני המועד, או ההודעה הראשונה למי שנכנס באיחור' ) ),
			'j_t30'                 => $t( 'j_t30', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, תזכורת קצרה כדי שהכול יהיה ברור מראש.\nעד {{deadline}} צריך להשלים אחת משתי אפשרויות: פתיחת חשבון במסגרת התוכנית, או, אם פתיחת חשבון לא מתאימה לך, הסדרת תשלום של {{amount_text}}.\nמעבר למחזור הבא אפשרי מבחינת הלימודים, אבל הוא לא מזיז את המועד.\nוגם אחרי תשלום הדלת פתוחה: פתיחת חשבון בתוך 3 חודשים מיום התשלום מאפשרת לקבל זיכוי לפי תנאי התוכנית.", array( 'params' => array( 'name', 'deadline', 'amount_text' ), 'buttons' => $btn_pay, 'meta_category' => 'utility', 'counts_toward_quota' => true, 'use_when' => '30 יום לפני המועד' ) ),
			'j_t7'                  => $t( 'j_t7', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, נשאר שבוע עד {{deadline}}, ולפי המערכת החשבון עוד לא נפתח.\nאם התכנון הוא לפתוח, כדאי לא להשאיר את זה לרגע האחרון. אנחנו כאן כדי לעזור בתהליך.\nאם פתיחת חשבון לא מתאימה לך, לפי ההתחייבות אפשר להסדיר תשלום של {{amount_text}}.\nוגם אחרי תשלום הדלת פתוחה: פתיחת חשבון בתוך 3 חודשים מיום התשלום מאפשרת לקבל זיכוי לפי תנאי התוכנית.", array( 'params' => array( 'name', 'deadline', 'amount_text' ), 'buttons' => $btn_pay, 'meta_category' => 'utility', 'counts_toward_quota' => true, 'use_when' => 'שבוע לפני המועד' ) ),
			'j_t0'                  => $t( 'j_t0', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, היום הוא המועד האחרון להשלמת ההתחייבות בתוכנית.\nלפי המערכת עדיין לא זוהתה פתיחת חשבון. אם כבר פתחת או שהחשבון באישור, חשוב לעדכן אותנו עכשיו כדי שנבדוק לפני התשלום.\nאם לא נפתח חשבון, לפי תנאי ההצטרפות יש להסדיר תשלום של {{amount_text}}.\nאנחנו לא נעלמים: התכנים והמחזור הבא נשארים פתוחים, ופתיחת חשבון בתוך 3 חודשים מיום התשלום מאפשרת לקבל זיכוי לפי תנאי התוכנית.", array( 'params' => array( 'name', 'amount_text' ), 'buttons' => $btn_last, 'meta_category' => 'utility', 'counts_toward_quota' => true, 'use_when' => 'ביום המועד' ) ),
			'l_intro'               => $t( 'l_intro', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS. חוזרים אליך לגבי התוכנית למתחילים.\nלפי הרישום אצלנו החשבון במסגרת התוכנית עדיין לא נפתח, והמועד שנקבע בהסכם, {{deadline}}, כבר עבר.\nעדיין אפשר לפתוח עכשיו, ונשמח לעזור. אם פתיחת חשבון לא מתאימה לך, לפי תנאי ההצטרפות יש להסדיר תשלום של {{amount_text}}.\nמה הכי מתאים עכשיו?", array( 'params' => array( 'name', 'deadline', 'amount_text' ), 'buttons' => $btn_last, 'meta_category' => 'utility', 'counts_toward_quota' => true, 'use_when' => 'תלמיד שהמועד שלו עבר לפני שנכנס לליווי (הצטרף לפני 1.8 או שהליווי הופעל מאוחר)' ) ),
			'd_link'                => $t( 'd_link', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS. בהמשך לעדכון שפתיחת חשבון לא מתאימה לך כרגע, אלה פרטי התשלום על התוכנית.\nהסכום הוא {{amount_text}}, ואפשר להסדיר אותו עד {{deadline}}.\nלתשלום בקישור המאובטח: {{link}}\nפתיחת חשבון בתוך 3 חודשים מיום התשלום מאפשרת לקבל זיכוי לפי תנאי התוכנית. שאלות? אפשר לכתוב לנו כאן.", array( 'params' => array( 'name', 'amount_text', 'deadline', 'link' ), 'requires_link' => true, 'meta_category' => 'utility', 'counts_toward_quota' => true, 'use_when' => 'מי שהודיע שלא יפתח חשבון, אחרי שאחראי גבייה אישר את החיוב ולפני המועד' ) ),
			'd_link_reply'          => $t( 'd_link_reply', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, כאן צוות INSIDERS. בהמשך לעדכון שפתיחת חשבון לא מתאימה לך כרגע, אלה פרטי התשלום על התוכנית.\nהסכום הוא {{amount_text}}, ואפשר להסדיר אותו עד {{deadline}}.\nאפשר להשיב כאן ונשלח את פרטי ההסדרה. פתיחת חשבון בתוך 3 חודשים מיום התשלום מאפשרת לקבל זיכוי לפי תנאי התוכנית.", array( 'params' => array( 'name', 'amount_text', 'deadline' ), 'meta_category' => 'utility', 'counts_toward_quota' => true, 'use_when' => 'כמו d_link, כשקישורי התשלום כבויים' ) ),
			'd_t0'                  => $t( 'd_t0', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, היום הוא המועד להסדרת התשלום על התוכנית, בסך {{amount_text}}.\nלתשלום בקישור המאובטח: {{link}}\nשאלות או קושי? אפשר לכתוב לנו כאן.", array( 'params' => array( 'name', 'amount_text', 'link' ), 'requires_link' => true, 'meta_category' => 'utility', 'counts_toward_quota' => true, 'use_when' => 'ביום המועד, למי שהודיע שלא יפתח ועדיין לא שילם' ) ),
			'd_t0_reply'            => $t( 'd_t0_reply', 'commitment_reminder', 'whatsapp_template', "היי {{name}}, היום הוא המועד להסדרת התשלום על התוכנית, בסך {{amount_text}}.\nאפשר להשיב כאן ונשלח את פרטי ההסדרה. שאלות או קושי? נשמח לעזור.", array( 'params' => array( 'name', 'amount_text' ), 'meta_category' => 'utility', 'counts_toward_quota' => true, 'use_when' => 'כמו d_t0, כשקישורי התשלום כבויים' ) ),
			// Replies inside the 24-hour window after the student wrote or pressed a button.
			'j_open_ack'            => $t( 'j_open_ack', 'service_reply', 'whatsapp_session', "מעולה, אנחנו איתך.\nנציג מהצוות יחזור אליך בהקדם כדי לתאם שיחה ולעבור יחד על פתיחת החשבון." ),
			'j_question_ack'        => $t( 'j_question_ack', 'service_reply', 'whatsapp_session', "בשביל זה אנחנו כאן. העברנו את הפנייה לצוות ונחזור אליך כאן בהקדם.\nאפשר כבר עכשיו לכתוב מה לא ברור או מה מעכב, וזה יעזור לנו לעזור." ),
			'j_pay_ack'             => $t( 'j_pay_ack', 'service_reply', 'whatsapp_session', "מעולה. הסכום לתשלום על התוכנית הוא {{amount_text}}.\nנשלח לך כאן קישור מאובטח לתשלום בהקדם. אם משהו לא ברור בינתיים, אפשר לכתוב לנו.", array( 'params' => array( 'amount_text' ) ) ),
			'j_declined_ack'        => $t( 'j_declined_ack', 'service_reply', 'whatsapp_session', "הבנתי, ולא נמשיך לפנות אליך לגבי פתיחת החשבון.\nלפי תנאי ההצטרפות, אם לא נפתח חשבון עד {{deadline}}, יש להסדיר תשלום של {{amount_text}}. נשלח לך כאן קישור מאובטח לתשלום.\nזה לא סוגר את הדלת: פתיחת חשבון בתוך 3 חודשים מיום התשלום מאפשרת לקבל זיכוי לפי תנאי התוכנית, והתכנים והליווי נשארים זמינים.", array( 'params' => array( 'deadline', 'amount_text' ) ) ),
			'j_opened_ack'          => $t( 'j_opened_ack', 'service_reply', 'whatsapp_session', "תודה על העדכון. ייתכן שהפתיחה עדיין לא נקלטה אצלנו.\nאפשר לשלוח כאן את שם הברוקר ואת תאריך הפתיחה, ונבדוק מול המערכת. עד שהבדיקה תסתיים לא נתקדם לתשלום." ),
			'j_stuck_ack'           => $t( 'j_stuck_ack', 'service_reply', 'whatsapp_session', "טוב שעדכנת. נבדוק איפה הפתיחה עומדת ומה חסר כדי להשלים אותה, ונחזור אליך כאן.\nכל עוד יש תהליך פתיחה שאפשר לאמת, לא נתקדם לתשלום לפני הבדיקה." ),
			'j_next_cohort'         => $t( 'j_next_cohort', 'service_reply', 'whatsapp_session', "אפשר בהחלט להשתלב במחזור הבא מבחינת הלימודים, ונמשיך ללוות אותך גם שם.\nרק חשוב להפריד: המעבר למחזור לא משנה את מועד ההתחייבות לפתיחת חשבון או להסדרת התשלום, שנשאר {{deadline}}.", array( 'params' => array( 'deadline' ) ) ),
			// After a program payment: a confirmation only. No reminders after payment (decision of 2026-10-10).
			'program_paid'          => $t( 'program_paid', 'payment_confirmation', 'whatsapp_template', "היי {{name}}, התשלום בסך {{paid}} ₪ התקבל, תודה.\nזה לא סוף הדרך: התכנים והליווי ממשיכים, ופתיחת חשבון בתוך 3 חודשים מיום התשלום מאפשרת לקבל זיכוי לפי תנאי התוכנית.\nרוצים להתקדם עם פתיחת חשבון? אפשר לכתוב לנו כאן.", array( 'params' => array( 'name', 'paid' ), 'meta_category' => 'utility' ) ),
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
		$problems = self::lint( (string) $next['body'], $next['kind'], (string) $next['channel'] );
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
	public static function lint( string $body, string $kind, string $channel = '' ): array {
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
		if ( 'whatsapp_template' === $channel ) {
			// Meta (error 2388299, enforced at creation since 2025-10): no variable at the start or end, none side by side.
			if ( preg_match( '/^\s*\{\{|\}\}[\s.!?]*$/u', $body ) ) {
				$p[] = 'תבנית לא יכולה להתחיל או להסתיים במשתנה (מטא דוחה)';
			}
			if ( preg_match( '/\}\}\s*\{\{/u', $body ) ) {
				$p[] = 'שני משתנים צמודים (מטא דוחה)';
			}
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

	/** {{name}} after the fixed "היי": the first name when reliable, otherwise "לך" ("היי לך"). Never empty. */
	public static function name_or_neutral( string $first_name ): string {
		return '' !== $first_name ? $first_name : 'לך';
	}
}
