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
	 * Word edges next to Hebrew use (?<!\p{L}) / (?!\p{L}): explicit, whatever PCRE's UCP setting.
	 * The business number is shared with the lessons, so the words must point at our messages,
	 * the number or the charge ("הסרטונים הפסיקו לעבוד" is not a stop).
	 */
	private const RULES = array(
		// Students write to this number about lessons too: "להפסיק את ההקלטה", "לבטל את הפגישה", "מי זה המרצה"
		// are not about us. The words have to point at the messages, the number or the charge.
		// English "stop" only as a whole short message or "stop messaging me": students write "stop loss" daily.
		// "Remove me from the group/community/Zoom" and "no messages from the group" are the community, not us.
		'stop'           => '/(^\s*(נא\s+|בבקשה\s+|please\s+)?(להסיר|הסר|הסרה|הסירו|הסירו אותי|תסירו אותי|stop|די|די כבר|מספיק|תפסיקו|תפסיקו כבר|unsubscribe)([\s,.!]+(בבקשה|תודה|כבר|מיד|עכשיו|please|now|it|הסר|הסרה|stop|די|מספיק))*[\s.!,\p{So}\x{FE0F}]*$|(?<!\p{L})[וש]?להפסיק\s+(לשלוח\s+(לי|הודעות|אליי)|לפנות\s+(אליי|אלי)|לכתוב\s+(לי|אליי)|לקבל\s+(מכם|מכן|את ההודעות|הודעות)|את ההודעות|עם ההודעות)(?!.{0,25}(קבוצ|קהיל|פורום|טלגרם|דיסקורד|ערוץ|זום|מחזור|ברוקר|פוסט))|(?<!\p{L})(תפסיקו|תפסיק|תפסיקי)\s+(כבר\s+)?(לשלוח|לכתוב|לפנות|להטריד|להציק|לחפור|לנדנד|לשגע|להתקשר|עם\s+(ה)?הודעות|עם\s+זה|את ההודעות)(?!.{0,25}(קבוצ|קהיל|פורום|טלגרם|דיסקורד|ערוץ))|(?<!\p{L})הפסיקו\s+(כבר\s+)?(להטריד|להציק|לשלוח\s+(לי\s+)?(הודעות|יותר)(?!\s+(על|של|מה)))|(?<!\p{L})(הסר|הסירו|תסירו|תסיר|תסירי|תורידו|תוריד|תורידי|הורידו|תוציאו|תוציא|תוציאי|הוציאו|תמחקו|תמחק|תמחקי|מחקו|להסיר|להוריד|להוציא|למחוק)\s+(אותי|את\s+(ה)?מספר(\s+(שלי|הזה|הטלפון\s+שלי))?|את\s+מספרי)(?!.{0,25}(קבוצ|קהיל|קורס|שיעור|זום|לייב|פורום|טלגרם|דיסקורד|ערוץ|מחזור|וובינר|סדנ|אתר|פלטפורמ|מתחילים|מתקדמים))|(?<!\p{L})ש?לא\s+(לשלוח\s+(לי\s+)?(יותר|הודעות|עוד)|לפנות\s+(אליי|אלי|לי)|רוצה\s+(לקבל\s+)?(מכם\s+|מכן\s+)?(יותר\s+|עוד\s+|שום\s+)?(הודעות|את ההודעות|הודעה)|מעוניי(ן|נת|נים)\s+(לקבל\s+)?(מכם\s+|מכן\s+)?(יותר\s+|עוד\s+|שום\s+)?(ב?הודעות|ב?את ההודעות|הודעה|הודעות))(?!.{0,25}(קבוצ|קהיל|פורום|טלגרם|דיסקורד|ערוץ|זום|מחזור|ברוקר|פוסט))|(?<!\p{L})לא\s+(רוצה|מעוניי(ן|נת|נים))\s+ש(ת|י)(שלחו|פנו|כתבו|תקשרו)|(?<!\p{L})ש?לא\s+(ת|י)(שלחו|פנו|כתבו)\s+(לי\s+|אליי\s+|אלי\s+)?(יותר|הודעות|עוד|שום|כלום)|(?<!\p{L})ו?אל\s+(תשלחו|תשלח|תשלחי|תכתבו|תכתוב|תכתבי|תפנו|תפנה)\s+(לי\s+|אליי\s+|אלי\s+)?(יותר|הודעות|עוד|שום|כלום)|(?<!\p{L})ו?אל\s+(תשלחו|תכתבו|תפנו)\s+(לי|אליי|אלי)[\s.!]*$|(?<!\p{L})(די|מספיק)\s+(כבר\s+)?(עם\s+(ה)?הודעות|לשלוח|להטריד|לחפור|להציק)|(?<!\p{L})בלי\s+(יותר\s+הודעות|הודעות\s+יותר)|stop\s+(messaging|texting|sending|contacting|writing)(\s+me)?|remove\s+me\s+from|(?<!\p{L})unsubscribe(?!\p{L})|(?<!\p{L})opt[\s-]?out(?!\p{L}))/iu',
		'wrong_number'   => '/((?<!הכנסתי )(?<!הזנתי )(?<!רשמתי )(?<!הקלדתי )(?<!שמתי )מספר\s+שגוי|טעות\s+במספר(?!\s+(ה)?(חשבון|ת"ז|תעודת|זהות|אשראי|כרטיס))|טעיתם\s+(ב)?(מספר|בן\s+אדם|אדם)|(?<!\p{L})טעיתם[\s.!,]*$|(?<!\p{L})טעות[\s,]+אין\s+(פה|כאן)|אין\s+(פה|כאן)\s+אף\s+אחד\s+בשם|(?<!\p{L})ל?(ה)?מספר\s+(ה)?לא\s+נכון|(?<!\p{L})(ה)?מספר\s+(הזה\s+)?לא\s+(שייך|של)\s|(?<!\p{L})זה\s+לא\s+אני(?=[\s.!,]*$|[\s,]+(טעיתם|טעות|לא\s+נרשמתי|אני\s+לא|אין\s+(פה|כאן)))|זה\s+לא\s+(ה)?(מספר|טלפון|פלאפון|נייד)\s+(של|שלו|שלה)|זה\s+(ה)?(מספר|טלפון|פלאפון)\s+של\s+(אמא|אבא|הבן|הבת|אשתי|בעלי|אחי|אחותי|סבא|סבתא)|לא\s+מכיר(ה|ים)?\s+(אתכם|אותכם|את\s+המספר|את\s+השם|אף\s+אחד\s+בשם|שום\s+(אינסיידרס|insiders|חברה|תוכנית|קורס))|מאיפה\s+(יש\s+לכם|השגתם|הבאתם|לקחתם)\s+(את\s+)?(ה)?מספר|לא\s+נרשמתי\s+(לשום\s+(דבר|קורס|תוכנית)|לכלום|אליכם)|קיבלתי\s+(את\s+)?(ה)?הודע(ה|ות)\s+(הזאת\s+|הזו\s+|האלה\s+)?בטעות|הגיע(ה|ו)?\s+אליי\s+בטעות|^\s*(סליחה,?\s+)?מי\s+(זה|זאת|זו|אתם|אתה|את|מדבר|כותב)(\s+(בכלל|פה|זה|מדבר|מדברת|כותב|כותבת))?\s*[?!.]*\s*$|(?<!\p{L})מי\s+אתם(?!\p{L})|wrong\s+(number|person)|who\s+(is\s+this|are\s+you)|who\'s\s+this)/iu',
		'dispute'        => '/(^\s*(ביטול|לבטל|בקשת ביטול|ביטול עסקה|ביטול חיוב)\s*[.!]*\s*$|(?<!\p{L})לא\s+חייב(ת|ים)?\s+(לכם|לך|לכן|לאף\s+אחד|כלום|שום|שקל|כסף|את\s+ה?(כסף|סכום|חיוב|תשלום))|(?<!\p{L})לא\s+מגיע\s+לכם|(?<!\p{L})[וש]?(לבטל|ביטול|ת?בטלו|תבטל|תבטלי|מבטל|מבטלת|מבטלים)\s+(לי\s+)?(את\s+)?(ה)?(חיוב|עסקה|תשלום|הוראת|הסכם|מנוי|הרשמה|התחייבות|תוכנית|קורס|השתתפות|ליווי|הכל|זה(?!\p{L}))(?!.{0,6}(ל|ב|מ)ה?(שיעור|זום|לייב|וובינר|מפגש|פגישה|סדנ|הרצאה|כנס|קהיל))|(?<!\p{L})(רוצה|רוצים|מבקש|מבקשת|מבקשים|אבקש|דורש|דורשת|החלטתי|צריך|צריכה)\s+(ל)?(בטל|ביטול)(?!\p{L})(?!\s+(לי\s+)?(את\s+)?(ה)?((הרשמה|השתתפות|מנוי|הגעה)\s+(ל|ב)\s*ה?)?(קבלת|הודעות|שיעור|זום|לייב|וובינר|מפגש|פגישה|שיחה|סדנ|הרצאה|הקלטה|התראות|תזכורת|הזמנה|תור))|החזר(\s+|-)?(כספי|כסף|מלא|ה?כסף)|מגיע\s+לי\s+(החזר|זיכוי)|(תחזירו|תחזיר|תחזירי|להחזיר)\s+(לי\s+)?(את\s+)?(ה)?כסף|(?<!\p{L})זיכוי(?!\p{L})|refund|chargeback|cancel\s+(my|the)\s+(subscription|payment|charge|order|enrollment|membership)|הכחשת\s+עסקה|(אפנה|פונה|פניתי|אתקשר|אגיש)\s+(\p{L}+\s+)?ל?(חברת\s+)?(ה)?אשראי|לא\s+אישרתי\s+(את\s+)?(ה)?(חיוב|תשלום|עסקה|זה)|לא\s+נתתי\s+(את\s+)?(ה)?(אישור|הסכמה|רשות)|(?<!\p{L})(בלי|ללא)\s+(ה)?(אישור|הסכמה)\s+(שלי|ממני)|(?<!\p{L})(חייבו|חייבתם|חייבתן)\s+אותי(?!\s+ל\p{L})|ה?חיוב\s+(הזה\s+)?(כפול|לא\s+(מוסכם|מאושר|חוקי|מוצדק|הוגן)|שגוי|שלא\s+אישרתי)|למה\s+(אתם\s+)?(מחייבים|חייבתם|חייבו|ירד\s+לי)|(?<!\p{L})לא\s+(משלם|משלמת|אשלם|הולך\s+לשלם|הולכת\s+לשלם)(?!\s+(עכשיו|היום|כרגע|החודש|בזמן|לפני|עד|ב-?\d))|(?<!\p{L})(לצאת|לעזוב|לפרוש|עוזב|עוזבת|פורש|פורשת)\s+(מ|את\s+)ה?(תוכנית|קורס|מסלול|התחייבות|הסכם)|זה\s+לא\s+מה\s+ש(סיכמנו|הבטחתם|אמרו|הובטח|נאמר|סגרנו)|לא\s+(אמר|אמרו|הסביר|הסבירו|סיפר|סיפרו)\s+לי\s+(ש|על).{0,25}(חיוב|תשלום|לשלם|יחייבו|לחייב)|בקשת\s+ביטול|מחלוקת|לא\s+הסכמתי(?!\s+עם)|(?<!עוד )(?<!עדיין )לא\s+חתמתי(?!\s+(עדיין|עוד))(?!.{0,30}(ברוקר|טופס|טפסים|טפס|מסמכ|בקשת\s+פתיחה))|עורך\s+דין|עורכת\s+דין|עו"ד|תביעה|לתבוע|אתבע|הגנת\s+הצרכן|(?<!\p{L})(זו|זאת|זה|ממש|פשוט|אתם)\s+(הונאה|רמאות|גניבה|עוקץ|נוכלות|רמאים|נוכלים|גנבים)(?!\p{L})|(?<!\p{L})(רמאים|נוכלים|גנבים|גונבים|גנבתם)(?!\p{L})|תלונה\s+(על\s+)?(ה)?(חיוב|תשלום|גבייה)|אגיש\s+תלונה)/iu',
		'human'          => '/(^\s*(נציג|נציגה|בן אדם|אדם אמיתי)(\s+בבקשה)?\s*[?!.]*\s*$|(?<!\p{L})(רוצה|רוצים|אפשר|צריך|צריכה|מבקש|מבקשת|אבקש|תנו\s+לי|תביאו|יש)\s+(\p{L}+\s+){0,2}?(ה)?(נציג|נציגה|בן\s+אדם|אדם\s+אמיתי|מישהו\s+אמיתי|אנושי|אחראי|מנהל|מנהלת)(?!\p{L})|(?<!\p{L})(רוצה|רוצים|אפשר|צריך|צריכה)\s+מישהו\s*[?!.]*\s*$|לדבר\s+עם\s+(ה)?(מישהו|נציג|נציגה|בן\s+אדם|אדם|אחראי|מנהל|מנהלת)|(תעבירו|תעביר|להעביר)\s+אותי\s+ל(נציג|נציגה|בן\s+אדם|מישהו|אחראי|מנהל)|(תתקשרו|תתקשר|תתקשרי|תחזרו|תחזור|תחזרי)\s+(אליי|אלי|לי)|(?<!\p{L})תתקשרו(?!\p{L})|(?<!\p{L})(תדברו|תדבר|תדברי)\s+איתי|ש(מישהו|נציג|נציגה)\s+(יחזור|תחזור|יתקשר|תתקשר|ידבר|תדבר)|שיחה\s+טלפונית|אפשר\s+(שיחה|טלפון)(?!\p{L})|טלפון\s+אליי|מישהו\s+מהצוות(?!\s+(אמר|אמרה|כתב|כתבה|הסביר|הסבירה))|talk\s+to\s+(a\s+)?(person|human|someone|representative|agent)|call\s+me)/iu',
		// "העברתי" alone is forwarding (a link, a lesson, a stop-loss): it counts only next to money words or alone.
		// Negated forms ("לא שילמתי", "עוד לא פתחתי") are not claims.
		'claims_paid'    => '/((?<!לא )(?<!\p{L})(כבר\s+)?(שילמתי|שלמתי|שילמנו|שלמנו|שילמתיי)(?!\p{L})(?!\s+על\s+(ה)?(מנוי|זום|tradingview|טריידינג|אפליקצי|נתונים|דאטה|עמלה|עמלות|פלטפורמ|אינדיקטור))|(?<!\p{L})(אבא|אמא|בעלי|אשתי|ההורים|הבן|הבת)\s+(\p{L}+\s+)?(שילם|שילמה|שילמו)(?!\p{L})|(?<!לא )(?<!\p{L})(כבר\s+)?(העברתי|העברנו)(?=[\s.!,\p{So}\x{FE0F}]*$|\s+(כבר\s+)?(לכם|אליכם|לחשבון\s+שלכם|ב?ביט|בפייבוקס|בהעברה|אתמול|היום|הבוקר|לפני|את\s+ה?(תשלום|סכום|יתרה)|את\s+ה?כסף(?!\s+ל(חשבון|ה?ברוקר))|כסף(?!\s+ל(חשבון|ה?ברוקר))|תשלום|\d|₪))|(?<!לא )(?<!\p{L})(כבר\s+)?(שולם|שולמה|שולמו)(?!\p{L})|(?<!לא )(?<!\p{L})הסדרתי(?!\s+(את\s+)?(ה)?(סיסמ|חיבור|זום|מחשב|כניסה))|(ביצעתי|עשיתי)\s+(את\s+)?(ה)?(תשלום|העברה|ביט)|(שלחתי|העברתי|עשיתי)\s+(לכם\s+)?(ב)?(ביט|פייבוקס|paybox|bit)(?!\p{L})|(?<!\p{L})ה?תשלום\s+(כבר\s+)?(בוצע|עבר|ירד|הועבר|התקבל)|(?<!\p{L})ה?העברה\s+(כבר\s+)?(בוצעה|עברה|יצאה)|אסמכתא|אסמכתה|(שלחתי|מצרף|מצרפת|הנה|צירפתי|מצורפת|מצורף)\s+(לכם\s+)?(את\s+)?(ה)?(קבלה|אישור\s+(ה)?(תשלום|העברה))|צילום\s+(מסך\s+)?(של\s+)?(ה)?(העברה|תשלום)|סגרתי\s+(את\s+)?(ה)?(חוב|תשלום|יתרה)|ה?כסף\s+(כבר\s+)?ירד\s+(לי\s+)?(מה)?(חשבון|אשראי|כרטיס)|(?<!\p{L})(i\s+)?(already\s+)?(paid|transferred)(?!\p{L}))/iu',
		'stuck'          => '/((תקוע|תקועה|נתקע|נתקעה).{0,40}(פתיח|חשבון)(?!\s*(ב|של\s+ה)?(אתר|זום|דשבורד|קורס))|(פתיח|חשבון).{0,40}(תקוע|תקועה|נתקע|נתקעה)|התחלתי (את )?ה?פתיחה|בתהליך (של )?פתיחה|החשבון בבדיקה|(החשבון|הפתיחה) (ממתין|ממתינה|מחכה) לאישור|(מחכה|ממתין|ממתינה|מחכים)\s+(עדיין\s+)?ל?אישור\s+(מה|של\s+ה)?ברוקר|(שלחתי|העליתי)\s+(את\s+)?(ה)?מסמכים\s+(ל|ב)ה?ברוקר)/iu',
		'opened_account' => '/((?<!לא )(?<!\p{L})(כבר\s+)?(פתחתי|פתחנו)\s*(כבר\s+|אתמול\s+|היום\s+|השבוע\s+|הבוקר\s+)?(את\s+)?(ה)?(חשבון|תיק)(?!\s+(ב|ל|של)\s*-?\s*ה?(זום|גוגל|אתר|קהיל|פלטפורמ|טלגרם|דיסקורד|קורס|ג\'ימייל|מייל|אפליקצי|יוטיוב|פייסבוק|אינסטגרם|דשבורד|מערכת|tradingview|טריידינג|דמו))(?!\s+דמו)|(?<!לא )(?<!\p{L})(כבר\s+)?(פתחתי|פתחנו)\s+(אצל|דרך)\s+\S+|(?<!לא )(?<!\p{L})(כבר\s+)?(פתחתי|פתחנו)\s+ב-?\s*ה?(?!(טלפון|מחשב|זום|אתר|אפליקצי|דפדפן|דמו|מצגת|שיעור))\S+[\s.!,\p{So}\x{FE0F}]*$|(?<!לא )(?<!\p{L})(כבר\s+)?(פתחתי|פתחנו)(\s+(כבר|אתמול|היום))*[\s.!,\p{So}\x{FE0F}]*$|(?<!\p{L})כבר\s+פתחתי(?!\s+(את\s+)?(ה)?(זום|לינק|קישור|מצלמה|מיקרופון|שיעור|הקלטה|סרטון|מצגת|קובץ|אפליקצ|מייל|הודעה|טופס|אתר|דף|דשבורד|מחשב|קורס))|(?<!\p{L})יש\s+לי\s+(כבר\s+)?חשבון(?=[\s.!,\p{So}\x{FE0F}]*$|\s+(כבר|מסחר|פתוח|פעיל|אצל|בברוקר|אצלם|אצלכם))|(?<!\p{L})[שו]?(ה)?חשבון\s+(שלי\s+)?(כבר\s+)?(נפתח|פתוח|אושר|מאושר)(?!\p{L})(?!\s+(ב|על)\s*(שני|מכשיר|מחשב|טלפון|דפדפן))|(נפתח|אושר)\s+לי\s+(כבר\s+)?(ה)?חשבון|סיימתי\s+(את\s+)?(כל\s+)?(ה)?(פתיחה|לפתוח)|סיימתי\s+(את\s+)?(כל\s+)?(ה)?תהליך.{0,25}(ברוקר|פתיחה|חשבון)|(קיבלתי|הגיע|הגיעה)\s+(לי\s+)?(\p{L}+\s+){0,2}?אישור\s+(מה|של\s+ה)?ברוקר|(?<!לא )(?<!\p{L})הפקדתי(?!\p{L})|(?<!לא )(?<!\p{L})(העברתי|הפקדתי)\s+(את\s+)?(ה)?כסף\s+ל(חשבון|ה?ברוקר)|(?<!\p{L})(עם|יש\s+לי)\s+חשבון\s+(מסחר\s+)?פעיל|פתוח\s+(כבר\s+)?אצל\s+ה?ברוקר|already\s+opened|opened\s+(an|the|my)\s+account|account\s+is\s+(open|approved))/iu',
		// Before wants_to_open: "לא רוצה לפתוח" contains "רוצה לפתוח".
		// "לא רוצה לפתוח את המצלמה / עסקאות בלילה" is not the account: the words after must be the account, an end or a softener.
		'declines_open'  => '/((?<!\p{L})לא\s+(אפתח|רוצה\s+לפתוח|מתכוו(ן|נת|נים)\s+לפתוח|מעוניי(ן|נת|נים)\s+לפתוח|בא\s+לי\s+לפתוח|מתאים\s+לי\s+לפתוח)(?=[\s.!,\p{So}\x{FE0F}]*$|\s+(את\s+)?(ה)?(חשבון|ברוקר)|[\s,]+(כרגע|בינתיים|עכשיו|בכלל|תודה|אצל|אצלכם|שום|לא\s+משנה))|(?<!\p{L})לא\s+(אפתח|פותח|פותחת|נפתח|פותחים)\s+(את\s+)?(ה)?(שום\s+)?חשבון|(?<!\p{L})לא\s+(רוצה|מעוניי(ן|נת|נים)\s+ב)\s*(ה)?(חשבון|ברוקר)(?!\p{L})(?!\s+(ב|של)\s*ה?(זום|אתר|קהיל|גוגל|פלטפורמ))|(מעדיף|מעדיפה|החלטתי|בחרתי|העדפתי)\s+(ש)?(אני\s+)?לא\s+(לפתוח|פותח|פותחת|אפתח)|מוותר(ת)?\s+על\s+(ה)?(פתיחה|חשבון)|אין\s+לי\s+(שום\s+)?כוונה\s+לפתוח)/iu',
		'wants_to_open'  => '/((רוצה לפתוח|אפשר לפתוח|איך פותחים|לפתוח עכשיו|עזרה בפתיחה)(?!\s+(את\s+)?(ה)?(מצלמה|מיקרופון|זום|לינק|קישור|קובץ|שיעור|הקלטה|סרטון|מצגת|מייל|אפליקצי|דף|אתר|חלון|וידאו|קורס|קבוצה|מודול|עסק|פוזיצי)))/iu',
		'hardship'       => '/(פריסה|בתשלומים|קשה\s+לי\s+(כלכלית|לשלם|מבחינה\s+כלכלית|עם\s+ה?(כסף|תשלום|תשלומים|חיוב))|^\s*קשה\s+לי[\s.!]*$|אין\s+לי\s+(כרגע\s+|עכשיו\s+)?(את\s+)?(ה)?(כסף|מאיפה|הסכום)|מצב\s+כלכלי|לא\s+(יכול|יכולה|מסוגל|מסוגלת)\s+לשלם|מובטל|מובטלת|פוטרתי|פיטרו\s+אותי|(?<!\p{L})במינוס(?!\p{L})|הנחה)/iu',
		'promise'        => '/(אשלם|אסדיר|אסדר(?!\p{L})|אעביר(?!\s+(את\s+)?(ה)?(לינק|קישור|הקלטה|שיעור|מצגת))(?!\s+לחבר)|אחרי\s+ה?(משכורת|חגים|חג)(?!\p{L})|כשתיכנס\s+ה?משכורת|^(?!.*(שיעור|זום|לייב|הקלט|וובינר|מפגש|פגישה|מרצה|הרצא|סדנ|מחזור)).*(בתחילת\s+החודש|בסוף\s+החודש|עד\s+סוף\s+ה?(שבוע|חודש)|בשבוע\s+הבא|(עד\s+|ב)יום\s+(ראשון|שני|שלישי|רביעי|חמישי|שישי)|(?<!\p{L})ב(ראשון|שני|שלישי|רביעי|חמישי)(?!\p{L})|עד\s+ה?-?\s?\d{1,2}|ב-?\s?\d{1,2}\s*(ל|\/)\s*\d{1,2}|(?<!\p{L})ב-?\s?\d{1,2}\s+ל?חודש|(?<!\p{L})מחר(?!\p{L})))/iu',
		'next_cohort'    => '/(מחזור הבא|למחזור אחר|לעבור מחזור|להצטרף למחזור|לדחות את ה?השתתפות)/iu',
		'bot_question'   => '/((?<!\p{L})(ה|צ\'אט\s*)?(בוט|רובוט)(?!\p{L})(?!\s+(ל|של\s+)?מסחר)(?!\s+טריידינג)|(הודעה|תשובה|מענה|מערכת|הודעות)\s+אוטומטי(ת|ות)?(?!\p{L})(?!\s+(ל|של\s+)?מסחר)|אדם\s+או\s+(מחשב|בוט|מכונה|רובוט)|(?<!\p{L})(את|אתה|אתם|זה|זו|זאת)\s+(AI|בינה\s+מלאכותית|צ\'אט\s*GPT|chatgpt|gpt)(?!\p{L})|are\s+you\s+(a\s+)?(bot|robot|human|real|ai)(?!\p{L}))/iu',
		'link_request'   => '/(^(?!.*(זום|zoom|שיעור|לייב|קהיל|קבוצ|הקלט|וובינר|מצגת|טלגרם|דיסקורד|מפגש|נפתח|נטען|עובד|לחבר)).*(קישור|לינק|link)|איך משלמים|איפה משלמים|איך אפשר לשלם|לשלם עכשיו)/iu',
	);

	public static function classify( string $text, string $type, bool $is_button = false ): array {
		if ( in_array( $type, array( 'audio', 'voice', 'ptt' ), true ) ) {
			return array( 'intent' => 'voice', 'source' => 'rules', 'confidence' => 'rule' );
		}
		if ( in_array( $type, array( 'image', 'document', 'video', 'sticker' ), true ) && '' === trim( $text ) ) {
			return array( 'intent' => 'media', 'source' => 'rules', 'confidence' => 'rule' );
		}
		// A quick-reply button press arrives as its exact title: a decision, not text to interpret.
		// The same words typed by hand go through the rules like any text.
		$button = ( $is_button || in_array( $type, array( 'button', 'interactive', 'quick_reply' ), true ) ) ? Journey::button_intent( $text ) : null;
		if ( $button ) {
			return array( 'intent' => $button, 'source' => 'button', 'confidence' => 'rule' );
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
		$ai_case = $cases[0] ?? null;
		// Before the deadline the beginner-program replies have their own answers (Journey);
		// whatever it does not handle continues through the table below.
		$cases  = Journey::on_intent( $name, (string) ( $intent['source'] ?? 'rules' ), $cases, $customer_id, $message_id, $msg );
		if ( in_array( $name, array( 'question', 'declines_open', 'wants_to_pay', 'stuck', 'next_cohort' ), true ) ) {
			// Outside the pre-deadline phase these are ordinary messages for a person. An opening "in
			// progress" still matters after a program charge was approved; elsewhere it is just text.
			$program = (bool) array_filter( $cases, static fn( $c ) => 'non_open_charge' === $c['source_type'] );
			$name    = 'wants_to_pay' === $name ? 'link_request' : ( ( 'stuck' === $name && $program ) ? 'opened_account' : 'unclear' );
		}
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
					Tasks::open( 'reply:' . $c['id'], 'reply_review', array( 'append' => true, 'case_id' => (int) $c['id'], 'customer_id' => $customer_id, 'reason' => ( self::LABELS[ $name ] ?? 'הודעה נכנסת' ) . ': ' . mb_substr( (string) ( $msg['text'] ?? '' ), 0, 200 ) ) );
				}
				if ( $first && in_array( $name, array( 'human', 'voice' ), true ) ) {
					Messaging::service_reply( (int) $first['id'], 'handoff' );
				}
				break;
		}

		$ai_case = $first ?? $ai_case;
		if ( $ai_case && Settings::on( 'cap_ai_suggestions' ) ) {
			// Suggestion only, stored for the rep. Failure here changes nothing above.
			Classifier::suggest_later( (int) $ai_case['id'], $message_id );
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
