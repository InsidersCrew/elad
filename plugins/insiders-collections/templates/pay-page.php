<?php
/**
 * Customer pay page. Functional baseline in INSIDERS colors; the final visual
 * design is specified in docs/design/designer-brief.html. Variables in scope:
 * $s (state array), $state, $form_token, $action.
 */
defined( 'ABSPATH' ) || exit;
use Insiders\Collections\Support\Money;

$wa    = (string) \Insiders\Collections\Support\Settings::get( 'support_whatsapp', '' );
$name  = (string) ( $s['first_name'] ?? '' );
$copy  = array(
	'ready'     => array( 'title' => ( '' !== $name ? 'היי ' . $name : 'היי' ), 'lead' => 'זה פירוט התשלום שמופיע אצלנו כפתוח.' ),
	'changed'   => array( 'title' => 'הסכום עודכן', 'lead' => 'מאז שנשלח הקישור הסכום השתנה. זה הסכום העדכני לתשלום.' ),
	'paid'      => array( 'title' => 'התשלום כבר התקבל', 'lead' => 'לא נדרשת פעולה נוספת. תודה!' ),
	'expired'   => array( 'title' => 'הקישור כבר לא בתוקף', 'lead' => 'אפשר לכתוב לנו בוואטסאפ ונשלח קישור עדכני.' ),
	'checking'  => array( 'title' => 'בודקים את מצב התשלום', 'lead' => 'זה לוקח רגע. כדי לא לחייב פעמיים, אפשר לנסות שוב בעוד כמה דקות או לכתוב לנו.' ),
	'on_hold'   => array( 'title' => 'נציג מטפל בנושא', 'lead' => 'כרגע התשלום בבדיקה אצל הצוות שלנו. נחזור אליך בוואטסאפ.' ),
	'paused'    => array( 'title' => 'התשלום המקוון לא זמין כרגע', 'lead' => 'אפשר לכתוב לנו בוואטסאפ ונעזור.' ),
	'not_found' => array( 'title' => 'הקישור לא נמצא', 'lead' => 'ייתכן שהוא הועתק חלקית. אפשר לכתוב לנו בוואטסאפ.' ),
	'error'     => array( 'title' => 'משהו השתבש', 'lead' => 'לא בוצע חיוב. אפשר לנסות שוב או לכתוב לנו.' ),
	'returned'  => array( 'title' => 'קיבלנו את התשלום בטרנזילה', 'lead' => 'אישור סופי יישלח אליך בוואטסאפ ברגע שהתשלום יאומת. אין צורך לשלם שוב.' ),
)[ $state ] ?? array( 'title' => 'משהו השתבש', 'lead' => 'לא בוצע חיוב.' );
?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta property="og:title" content="INSIDERS · תשלום מאובטח">
<meta property="og:description" content="עמוד תשלום מאובטח של INSIDERS">
<title>INSIDERS · תשלום</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;700;900&display=swap" rel="stylesheet">
<style>
.icolp{--bg:#05142F;--primary:#004DAA;--accent:#0097FE;--sky:#83CDFF;--hl:#460FFF;--hl-h:#3A0BD6;--g07:rgba(239,244,248,.07);--g12:rgba(239,244,248,.12);--t2:rgba(255,255,255,.70);--t3:rgba(255,255,255,.45);--ok:#00C28A;--warn:#F5A623}
html{background:#05142F}
.icolp{margin:0;min-height:100vh;background:radial-gradient(120% 60% at 85% 0%,rgba(70,15,255,.16),transparent 60%),var(--bg);color:#fff;font-family:Heebo,system-ui,sans-serif;display:flex;align-items:flex-start;justify-content:center;padding:32px 16px 48px;box-sizing:border-box}
.icolp *{box-sizing:border-box}
.icolp-card{width:100%;max-width:440px;background:var(--g07);border:1px solid var(--g12);border-radius:24px;padding:28px 22px;backdrop-filter:blur(8px)}
.icolp-logo{font-weight:900;font-size:22px;letter-spacing:.5px;margin:0 0 24px;direction:ltr;text-align:right}
.icolp-logo span{color:var(--hl)}
.icolp h1{font-size:26px;font-weight:800;margin:0 0 6px;line-height:1.25}
.icolp p.lead{color:var(--t2);margin:0 0 22px;font-size:16px;line-height:1.55}
.icolp-amount{font-variant-numeric:tabular-nums;font-size:40px;font-weight:900;margin:4px 0 2px}
.icolp-label{color:var(--sky);font-size:13px;font-weight:600;letter-spacing:.2px}
.icolp-items{list-style:none;margin:18px 0 0;padding:0;border-top:1px solid var(--g12)}
.icolp-items li{display:flex;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid var(--g12);font-size:15px}
.icolp-items li span:last-child{font-variant-numeric:tabular-nums;color:var(--t2)}
.icolp-btn{display:block;width:100%;margin-top:24px;padding:16px 18px;border:0;border-radius:999px;background:var(--hl);color:#fff;font:700 17px Heebo,sans-serif;cursor:pointer;text-align:center;text-decoration:none}
.icolp-btn:hover{background:var(--hl-h)}
.icolp-btn[disabled]{opacity:.75;cursor:progress}
@media (prefers-reduced-motion:reduce){.icolp *{transition:none!important;animation:none!important}}
.icolp-btn:focus-visible{outline:3px solid var(--sky);outline-offset:3px}
.icolp-btn.secondary{background:transparent;border:1px solid var(--g12);color:#fff;font-weight:600}
.icolp-trust{display:flex;align-items:center;gap:8px;color:var(--t3);font-size:13px;margin-top:14px;justify-content:center}
.icolp-foot{color:var(--t3);font-size:12px;margin-top:22px;line-height:1.6;text-align:center}
.icolp-state{width:44px;height:44px;border-radius:50%;display:grid;place-items:center;margin-bottom:14px;background:var(--g12);font-size:22px}
.icolp-state.ok{background:rgba(0,194,138,.18);color:var(--ok)}
.icolp-state.wait{background:rgba(245,166,35,.16);color:var(--warn)}
</style>
</head>
<body class="icolp">
<main class="icolp-card" aria-live="polite">
	<div class="icolp-logo">IN<span>/</span>SIDERS</div>
	<?php if ( in_array( $state, array( 'paid', 'returned' ), true ) ) : ?><div class="icolp-state ok" aria-hidden="true">✓</div><?php elseif ( in_array( $state, array( 'checking', 'on_hold' ), true ) ) : ?><div class="icolp-state wait" aria-hidden="true">…</div><?php endif; ?>
	<h1><?php echo esc_html( $copy['title'] ); ?></h1>
	<p class="lead"><?php echo esc_html( $copy['lead'] ); ?></p>
	<?php if ( in_array( $state, array( 'ready', 'changed' ), true ) ) : ?>
		<div class="icolp-label">לתשלום</div>
		<div class="icolp-amount"><?php echo esc_html( Money::format( (int) $s['amount_minor'] ) ); ?> ₪</div>
		<?php if ( count( $s['items'] ) > 1 ) : ?>
		<ul class="icolp-items">
			<?php foreach ( $s['items'] as $it ) : ?>
			<li><span><?php echo esc_html( $it['description'] ); ?></span><span><?php echo esc_html( Money::format( (int) $it['amount_minor'] ) ); ?> ₪</span></li>
			<?php endforeach; ?>
		</ul>
		<?php elseif ( $s['items'] ) : ?>
			<div class="icolp-trust" style="justify-content:flex-start;margin-top:4px"><?php echo esc_html( $s['items'][0]['description'] ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $s['link_route']['allowed'] ) ) : ?>
		<form method="post" action="<?php echo esc_url( $action ); ?>" id="icolp-form">
			<input type="hidden" name="t" value="<?php echo esc_attr( $form_token ); ?>">
			<button class="icolp-btn" type="submit" id="icolp-go">להמשך לתשלום מאובטח</button>
		</form>
		<script nonce="<?php echo esc_attr( \Insiders\Collections\Front\PayPage::$nonce ); ?>">
		// Interstitial: one click only. The page works without this script; it only prevents double submits.
		document.getElementById('icolp-form').addEventListener('submit', function () {
			var b = document.getElementById('icolp-go');
			if (b.disabled) { return; }
			b.disabled = true; b.textContent = 'מעבירים לעמוד התשלום המאובטח של טרנזילה…'; b.setAttribute('aria-busy', 'true');
		});
		</script>
		<div class="icolp-trust">🔒 התשלום מתבצע בעמוד המאובטח של טרנזילה. פרטי הכרטיס לא נשמרים אצלנו.</div>
		<?php else : ?>
		<p class="lead" style="margin-top:18px">אפשר לכתוב לנו בוואטסאפ ונשלח את פרטי ההסדרה.</p>
		<?php endif; ?>
	<?php endif; ?>
	<?php if ( '' !== $wa ) : ?>
		<a class="icolp-btn secondary" href="<?php echo esc_url( 'https://wa.me/' . preg_replace( '/\D/', '', $wa ) ); ?>">לכתוב לנו בוואטסאפ</a>
	<?php endif; ?>
	<div class="icolp-foot">INSIDERS · שאלות על התשלום? אפשר להשיב להודעה שקיבלת בוואטסאפ.</div>
</main>
</body>
</html>
