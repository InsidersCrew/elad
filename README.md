# INSIDERS CityOS

מערכת ויזואלית־ניהולית תלת־ממדית שמדמה את INSIDERS כעיר תפעולית.
שרשרת הערך = רבעים על טבעת. רכיבים תפעוליים = מבנים. זרימת לידים ותלמידים = תנועה בכביש הטבעת.
מבנים קטנים, כהים, סדוקים או עמוסים = בעיות עסקיות. בבית העירייה יושב **ראש העיר — Co-CEO** שמזהה את צוואר הבקבוק, מסביר למה, מסמלץ מהלכים ומכוון את הסקילים.

> זה לא דשבורד. זה **Decision Engine**: מראה → מפרש → מתעדף → מכוון פעולה.

## הפעלה

```bash
npm install
npm run dev        # http://localhost:5173 (בנייה מחדש אוטומטית)
npm run build      # dist/index.html — קובץ אחד עצמאי (~1MB) שאפשר להעלות לכל אחסון סטטי
npm test           # בדיקות המנוע
npm run mayor      # Daily Mayor Brief בטרמינל (Markdown)
npm run mayor -- --set fit_conv=0.36 --set call_duration_min=13   # דוח What-if + Brief על התרחיש
npm run report -- weekly     # daily | weekly | monthly
npm run snapshot   # out/city-snapshot.json — הקלט לסקילים
npm run period -- add "אוק׳ 26" fit_conv=31%   # הזנת חודש חדש ל-data/model.json (ראו "הזנת נתונים לחודש")
npm run screenshot # בדיקת עשן ויזואלית (Chromium headless) → out/*.png
```

## מה יש בעיר

| רובע | מה הוא מייצג | סקיל אחראי |
|---|---|---|
| שער הכניסה — Acquisition | פרסום, אורגני, דפי נחיתה, הפניות, WhatsApp | cityos-acquisition |
| רובע הסינון — Qualification | AI Agent, התנגדויות, לוגיקת סינון, handoff | cityos-qualification |
| רובע ההתאמה — Fit Call | קיבולת נציגים, המרה, תיאומים, זמן שיחה | cityos-rep-productivity |
| רובע האונבורדינג — Onboarding | אונבורדינג קבוצתי, welcome, גישה לקורס, קהילה | cityos-student-success |
| רובע פתיחת החשבון — Broker Handoff | ניתוב, סיוע לפתיחה, חריגים, SLA | cityos-broker-handoff |
| רובע המימוש — Monetization | הכנסות מחשבונות, חיוב 980 ₪, גבייה, ויתורים | cityos-economics |
| רובע ההצלחה — Student Success | check-ins, at-risk, קליניקות, מעורבות | cityos-student-success |
| רובע ההמשך — Growth & Upgrade | סוחר מקצועי, עסקאות אימון, צנרת מתקדמת | cityos-advanced-growth |
| רובע ההפניות — Referral | מנוע הפניות, טריגרים, מעקב — סוגר את המעגל לשער | cityos-referral |
| בית העירייה — City Hall | Mayor Office, War Room, הקצאת משאבים, מצפה סיכונים | cityos-mayor |

39 מבנים, 101 מדדים, 45 חוקי סיבה, 9 סקילים.

### איך מבנה נראה
- **גובה** = Size Score (תפוקה מול יעד) · **רוחב** = קיבולת (footprint)
- **צבע וזוהר** = לפי העדשה (ברירת מחדל: Health — ירוק / צהוב / אדום; סגול = בית העירייה)
- **חלונות כבויים** = בריאות נמוכה · **סדקים** = Strength < 60 · **משואה מהבהבת** = צוואר בקבוק קריטי / עומס יתר
- **טבעת כתומה + פקק חלקיקים** בכניסה לרובע = load / capacity > 85%
- **טיפות אדומות** שנשפכות מהכביש = דליפה (drop-off, רדומים, אי-גבייה) · **עשן** = מבנה לא רווחי

### עדשות (מצבי תצוגה)
בריאות · מבנים חלשים · צווארי בקבוק · רווחיות · תזרים · איכות שירות (מקשים 1–6).

### אינטראקציות
סיבוב / זום / גרירה · hover = tooltip · לחיצה על מבנה = פאנל פרטים (KPI, מגמות, ציונים, סיבות, מהלכים, סקיל, השפעה במעלה/מורד הזרם) · לחיצה כפולה = התמקדות ברובע · לחיצה על תווית רובע = תצוגת רובע.

## המנוע (`src/engine/`)

```
data/model.json ──► model.js (simulate)      ──► facts: כל מספר בעיר (זרימה, קיבולת, כלכלה, מזומן)
data/city.json  ──► metrics.js + scoring.js  ──► Health / Size / Strength / Risk / Bottleneck + דגלים
                    sensitivity.js           ──► Δתרומה לכל ידית (+10%) → מה באמת מגביל את העיר
data/rootcauses ──► rootcause.js             ──► למה, עם ראיות, ופעולות עם שינוי ידית
                    mayor.js                 ──► Co-CEO: מצב, 3 חלשים, דירוג צווארי בקבוק, מהלכים מסומלצים, second-order, מי מטפל, עדיפות, מה לנטר
                    scenario.js / reports.js ──► What-if, Daily / Weekly / Monthly (Markdown)
```

- **מודל תפעולי** (`model.js`): לידים → Qualified → שיחות → Joined → Started → נפתח / חיוב אי-פתיחה → הצלחה → אימון → הפניות (חוזרות לשער). קיבולת נציגים מחושבת מדקות: שיחות התאמה, שיחות handoff, אונבורדינג 1:1, follow-up, חריגים ואדמין. מעל 85% ניצולת המודל מטיל קנס המרה ומאריך תיאומים — ולכן רובע עמוס נראה "פעיל אבל חלש".
- **צוואר בקבוק ≠ חלש**: Bottleneck = 0.55·רגישות (Δתרומה מהידית הראשית) + 0.30·פער בריאות + 0.15·עומס ברובע. מבנה שה-KPI שלו אדום אבל שיפורו לא מזיז את העיר אינו צוואר הבקבוק.
- **מהלכים מסומלצים**: כל פעולה בחוקי הסיבה מוגדרת כשינוי ידית; ראש העיר מריץ אותה על המודל ומדווח Δתרומה, Δתלמידים, Δניצולת ואפקטים מסדר שני (עומס נציגים, מזומן כלוא, CAC, דליפה).
- **חוקים** (`data/rules.json`): weak < 60 · small = תפוקה מתחת ליעד ב-20%+ · overloaded = load/capacity > 85% · leaky = מדד דליפה אדום · fragile = 3 תקופות רעות ברצף · critical = חלש + צוואר בקבוק ≥ 60 + תלות במורד הזרם.

## נתונים (KPI ingestion)

`data/model.json` הוא מקור האמת. `inputs` = ערכי התקופה הנוכחית (כל ערך הוא ידית), `history` = ערכים לתקופות קודמות (למגמות). כל הערכים כרגע הם **נתוני דוגמה** — החליפו בנתונים אמיתיים (Pipedrive, דשבורד נציגים, ברוקר, Brevo).

### הזנת נתונים לחודש

כל תקופה (חודש) היא עמודה: `period.labels[i]` ↔ `history[lever][i]`. התקופה האחרונה היא העיר שרואים; הקודמות מזינות מגמות (3 תקופות רעות ברצף = שביר) ואת ה-Weekly Review.

**באפליקציה** — **נתונים** → שורת התקופות למעלה:
1. **+ חודש חדש** — נוסף חודש (השם מחושב אוטומטית, למשל ספט׳ 26 → אוק׳ 26, ואפשר לשנות). כל הערכים מועתקים מהחודש הקודם, כך שמזינים רק מה שהשתנה.
2. לוחצים על תקופה כלשהי כדי לערוך אותה. ליד כל ידית מופיע הערך של החודש הקודם; נקודה כחולה = השתנה מול החודש הקודם.
3. **שמור**. הנתונים נשמרים בדפדפן הזה (localStorage). כדי שהמאגר יהיה מקור האמת: **ייצא model.json** והחליפו את `data/model.json`.

**במאגר (CLI)** — מעדכן את `data/model.json` ישירות:

```bash
npm run period -- list                                   # התקופות ומה השתנה בכל אחת
npm run period -- add "אוק׳ 26"                          # חודש חדש, ערכים מועתקים מהקודם
npm run period -- add "אוק׳ 26" fit_conv=31% leads_paid=1050 open_rate=0.53
npm run period -- set "ספט׳ 26" call_duration_min=16     # תיקון של חודש קיים
npm run period -- set last waiver_rate=0.2               # "last" = התקופה הנוכחית
npm run period -- show last                              # כל הידיות של התקופה
npm run period -- remove-last
```

אחוזים מתקבלים כשבר (0.31) או עם סימן אחוז (31%). אחרי עדכון: `npm run mayor` ל-Brief על החודש החדש, ו-`npm run report -- weekly` להשוואה מול החודש הקודם.

מבנה הישויות (District, Structure, Metric, Skill, RootCause, Scenario) — ראו `data/*.json`; הסכמה מתועדת בתוך הקבצים.

## הסקילים (`.claude/skills/cityos-*`)

Claude Code מזהה אותם אוטומטית במאגר. תהליך העבודה:

1. `npm run snapshot` (או "הפעל סקיל" באפליקציה → "העתק פרומפט מלא").
2. `cityos-mayor` — מחזיר את 8 חלקי הפלט הקבועים + נרטיב CEO ומאציל.
3. הסקיל המומחה (למשל `cityos-rep-productivity`) מחזיר תוכנית עם ידיות "מ- → ל-".
4. `npm run mayor -- --set <lever>=<value>` מאמת את ההשפעה הצפויה לפני שמאמצים.

## מבנה המאגר

```
data/            city.json · model.json · rules.json · rootcauses.json · skills.json
src/engine/      המנוע (pure JS, רץ ב-Node ובדפדפן)
src/app/         Three.js: scene · layout · city · traffic · lenses · labels · interaction
src/app/ui/      topbar · mayorPanel · detailPanel · scenarioDrawer · reports · data · skill
scripts/         build · dev · mayor · report · snapshot · period · screenshot
test/            בדיקות המנוע (node --test)
.claude/skills/  9 סקילים
```

## מפת דרכים

- **MVP (כאן)**: 10 רבעים, 39 מבנים, KPI ingestion, Health scoring, Root cause, Co-CEO, 8 סקילים, Scenario mode, פאנלים, 4 דוחות.
- **V2**: התראות חכמות (ספי שינוי), תצוגת headcount load לפי נציג, historical replay (גרירת ציר זמן), forecast layer, תזמור סקילים אוטומטי (Mayor → skill → validation), חיבור ישיר ל-Pipedrive ולדשבורד הנציגים.

## עיצוב
דארק-פירסט על `#05142F`, היררכיית הכחולים של INSIDERS (`#004DAA` · `#0097FE` · `#83CDFF`), `#460FFF` כספוט אחד — בית העירייה. Heebo לטקסט, IBM Plex Mono למספרים. הלואדר הוא ה-N העולה של המותג.
