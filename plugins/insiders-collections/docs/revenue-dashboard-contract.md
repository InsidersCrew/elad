# חוזה החיבור לדשבורד ההכנסות

תוסף התשלומים לא מחשב מחדש את המועד שבו תלמיד בתוכנית למתחילים צריך להיות מחויב. את המועד הזה כבר מחשב דשבורד ההכנסות, ותוסף התשלומים קורא אותו ממנו. אפשר לחבר בשתי דרכים. מספיקה אחת מהן.

## דרך 1: מסנן בדשבורד (מומלץ)

מוסיפים לתוסף הדשבורד את הקוד הבא, ומחליפים את הפונקציה `my_dashboard_beginner_students()` בשליפה שהדשבורד כבר עושה:

```php
add_filter( 'icol_beginner_program_candidates', function ( $rows, $args ) {
	$out = array();
	foreach ( my_dashboard_beginner_students( $args['until'] ) as $s ) { // עד תאריך Y-m-d
		$out[] = array(
			'wp_user_id'        => (int) $s->user_id,           // חובה
			'name'              => $s->name,
			'email'             => $s->email,
			'phone'             => $s->phone,
			'program'           => 'תוכנית ליווי למתחילים',
			'joined_at'         => $s->joined,                  // Y-m-d
			'deadline'          => $s->deadline,                // Y-m-d — המועד האחרון לפתיחת חשבון
			'extension_until'   => $s->extension ?: '',         // Y-m-d אם ניתנה הארכה
			'account_opened'    => (bool) $s->opened,           // true / false; null אם לא ידוע
			'status_checked_at' => $s->checked ?: '',           // מתי נבדק הסטטוס מול הברוקר
			'agreement_ref'     => $s->agreement ?: '',
			'suggested_amount'  => '',                          // אופציונלי; הסכום נקבע ומאושר בתיק
		);
	}
	return $out;
}, 10, 2 );
```

שורה בלי `wp_user_id` מספרי לא נקלטת.

## דרך 2: מיפוי מפתחות user_meta

אם הדשבורד שומר את הנתונים כמאפייני משתמש, מריצים את כלי האבחון `revenue_probe`. הכלי מציג את המפתחות שקיימים באתר, עם ספירות וערכי דוגמה. את שמות המפתחות מזינים ב״הגדרות ← חיבורים״:

| הגדרה | מה המפתח מכיל |
|---|---|
| `revenue_meta_deadline` | המועד האחרון לפתיחת חשבון (חובה) |
| `revenue_meta_opened` | האם החשבון נפתח (`1`, `yes`, `true` או `opened`) |
| `revenue_meta_joined` | תאריך הצטרפות |
| `revenue_meta_extension` | מועד הארכה |
| `revenue_meta_phone` | טלפון (ברירת מחדל: `billing_phone`) |

## מה תוסף התשלומים עושה עם הנתונים

- **פעם ביום.** תלמיד שהמועד שלו עבר, כולל הארכה, והחשבון לא נפתח, נכנס למסך ״מועמדים לחיוב״. חוב לא נוצר אוטומטית: אחראי גבייה יוצר טיוטה, מזין סכום ובסיס ומאשר.
- **פתיחת חשבון אחרי הפעלת חוב.** אם הדשבורד מסמן שהחשבון נפתח אחרי שהחוב הופעל, התזכורות נעצרות והתיק עובר לנציג להחלטה.
- **הארכה אחרי טיוטה.** הארכה שניתנה אחרי שנוצרה טיוטה פותחת חריג.

## מה הדשבורד יכול לקרוא בחזרה

```php
$summary = icol_get_collection_summary( '2026-10-01', '2026-10-31' );
// $summary['charged'][source_type][currency]      — סכומים שאושרו לחיוב בתקופה (אגורות)
// $summary['collected'][source_type][currency]    — שיוכי תשלום נטו (אחרי היפוכים)
// $summary['written_off'][source_type][currency]  — זיכויים ומחיקות (לא נספרים כגבייה)
// $summary['outstanding_due_now'][...]            — יתרה פתוחה שהגיע מועדה
```

`source_type` מקבל אחד משלושה ערכים: `non_open_charge`, `recurring_failure` או `other`.

לעדכון בזמן אמת, אפשר להאזין לאירוע:
```php
add_action( 'icol_payment_allocated', function ( $allocation_id, $payment_id, $debt_item_id, $amount_minor ) { /* ... */ }, 10, 4 );
```
