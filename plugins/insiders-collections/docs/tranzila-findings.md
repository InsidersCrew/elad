# טרנזילה: מה התיעוד אומר ומה עוד פתוח

נקרא ב-17 באוקטובר 2026 מתוך docs.tranzila.com, אחרי התשובה של התמיכה. כל שורה מציינת את הדף שממנו היא נלקחה.

## מה אושר בתיעוד

| נושא | מה כתוב | הדף |
| :- | :- | :- |
| חתימה | HMAC-SHA256 של ה-app key, עם המפתח secret + זמן + nonce. ה-nonce בדוגמאות הוא 80 תווים | payments-and-billing/authentication, reports/authentication |
| My Billing | המודול רץ בדרך כלל על מסוף TOK, שלא דורש CVV לחיובים חוזרים | sto-api-for-my-billing/createstov2 |
| הודעת Notify של My Billing | השדות: supplier, sto_external_id, sum, currency, Response, ConfirmationCode, TranzilaTK, index, expdate, tranmode, contact, email, myid ועוד. אין שדה של מחזור או של מספר ניסיון, ואין חתימה | sto-api-for-my-billing |
| מייל תיקון כרטיס | נשלח אוטומטית בכשל של כרטיס גנוב, חסום, מזויף, פג תוקף או לא תקין. פעיל כברירת מחדל. אחרי עדכון הכרטיס נוצר טוקן חדש, והחיובים שנכשלו מנוסים שוב אוטומטית | sto-api-for-my-billing |
| עדכון הוראת קבע | v2/sto/update משנה רק סטטוס, פריטים והצמדה למדד. אין שדה של כרטיס או טוקן. המודול בתשלום | sto-api-for-my-billing/updatestov2 |
| שליפת הוראות קבע | v1/stos/get פעיל, מחזיר גם טוקן ותוקף של הכרטיס | sto-api-for-my-billing/getstos |
| דוח עסקאות | report.tranzila.com/v1/transaction מחזיר לכל עסקה credit_card_token, expiration_month, expiration_year וסכום, ומאפשר סינון לפי שדות. הסכום באגורות: עסקה אמיתית של 100 ₪ חזרה כ-10000 (בדיקה באתר החי, 17.10) | reports/tranzila-transaction-reports-api/gettransactions |
| שדות של העסק בעסקה | v1/terminals/settings/fields/list מחזיר את השם והכותרת של כל user_defined. בעסקת דמי רישום אמיתית מלאים user_defined_2 (9 תווים) ו-user_defined_5 (10 תווים) | reports/tranzila-transaction-reports-api/listterminalfields |
| Apple Pay | דף ה-iframe אומר שטרנזילה מחזירה טוקן לשימוש חוזר גם בתשלום Apple Pay, ובאותו דף, בסעיף Google Pay, כתוב "unlike Apple Pay". עסקת דמי הרישום שנבדקה שולמה ב-Apple Pay וחזר בה טוקן | iframe-integration-directng |
| חיוב לפי טוקן | v1/transaction/credit_card/create, הטוקן בשדה card_number, חודש ושנה חובה, CVV לא חובה בסכמה. מניעת חיוב כפול עם DCdisable, שדורש הגדרה של שדה 20 במסוף | tranzila-transactions-api-1/create-a-credit-card-transaction |
| בקשת תשלום | v1/pr/create, השדות החובה רשומים בדף. הודעת ה-Notify של התשלום כוללת pr_id | payment-request/get-v1-pr-create |
| Hosted Fields | מחייב Handshake, ו-Handshake מחייב רכישה של מודול הטוקנים | hosted-fields, handshake-v2 |
| קודי תשובה | 015 הוא כרטיס שפג תוקפו. התיעוד לא אומר באילו קודים יש ניסיון חוזר | transaction-response-codes |

## מה תוקן בתוסף בעקבות זה

- ה-nonce באורך 80 תווים, כמו בדוגמאות.
- בקשת התשלום נשלחת עם כל שדות החובה: created_by_system, request_date, payment_methods, מטבע כטקסט, טלפון בשלושה שדות, ופריטים עם type, unit_type ו-price_type. טרנזילה לא שולחת SMS או מייל בעצמה, הקישור יוצא בוואטסאפ.
- קוד 015 מסווג כפג תוקף.
- כלי האבחון מסתירים כל ערך של שדה user_defined וכל ערך שנראה כמו מייל, ומציגים את שם השדה כפי שהוגדר במסוף.
- סכומים מדוח העסקאות נקראים באגורות. הגדרה שנשמרה כ"שקלים" מתחלפת פעם אחת בשדרוג.

## מה עדיין פתוח מול טרנזילה

1. חיוב לפי טוקן שנוצר בתשלום דמי הרישום במסוף insiders: באיזה מסוף אפשר לחייב אותו בלי CVV, insiders או insiderstok? האם הטוקן תקף בשני המסופים?
2. איפה מגדירים את כתובת ה-Notify לתשלומים שבוצעו דרך קישור של בקשת תשלום? האם זו הגדרת ה-Notify של ה-iframe במסוף insiders?
3. האם My Billing מנסה שוב חיוב שנכשל גם בלי עדכון כרטיס? כמה פעמים ובאיזה מרווח?
4. כשחיוב שנכשל בהוראת קבע שולם בנפרד, איך מונעים ניסיון חוזר של אותו חיוב, למשל אחרי עדכון כרטיס, בלי לבטל את כל ההוראה?
5. האם יש בהודעת ה-Notify דרך להבחין בין ניסיון ראשון לניסיון חוזר של אותו תשלום?
6. האם יש חתימה או רשימת כתובות IP שמהן נשלחות הודעות ה-Notify?
7. האם יש לנו מודול טוקנים במסוף insiders, ומה העלות של Handshake לצורך Hosted Fields?
8. טוקן שנוצר בתשלום Apple Pay (בעסקה מופיע broker APPLE_PAY): האם אפשר לחייב אותו מאוחר יותר דרך transaction/credit_card/create, בלי שהלקוח נוכח?

עד שיש תשובה: התוסף מאמת כל הודעה מול דוח העסקאות, זיהוי המחזור נשאר ידני, ותשלום נפרד לפריט של הוראת קבע נשאר כבוי.
