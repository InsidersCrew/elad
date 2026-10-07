<?php
/**
 * Customer pay page, implemented from the designer's delivery
 * (INSIDERS Design System, ui_kits/payment): one card, one purple button only when
 * there is something to pay, every other state offers WhatsApp. Dark by default;
 * Settings 'pay_theme' = light switches to the designer's light variant.
 * Variables in scope: $s (state array), $state, $form_token, $action.
 */
defined( 'ABSPATH' ) || exit;
use Insiders\Collections\Support\Money;
use Insiders\Collections\Support\Settings;

$wa_digits = preg_replace( '/\D/', '', (string) Settings::get( 'support_whatsapp', '' ) );
$wa_url    = '' !== $wa_digits ? 'https://wa.me/' . $wa_digits : '';
$a11y_url  = (string) Settings::get( 'accessibility_url', '' );
$light     = 'light' === Settings::get( 'pay_theme', 'dark' );
$name      = (string) ( $s['first_name'] ?? '' );
$cur       = Money::symbol( (string) ( $s['currency'] ?? 'ILS' ) );
$nonce     = \Insiders\Collections\Front\PayPage::$nonce;

$icons = array(
	'lock'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
	'arrow'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>',
	'check'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
	'clock'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
	'person' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>',
	'link'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1"/></svg>',
	'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>',
	'pause'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/></svg>',
	'info'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
	'chat'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19.2z"/></svg>',
	'chev'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>',
);

// Copy and icon per state, as delivered. 'icon' => [tone, glyph]; no red anywhere.
$screens = array(
	'ready'     => array( 'title' => '' !== $name ? 'היי ' . $name : 'היי', 'text' => 'זה פירוט התשלום שמופיע אצלנו כפתוח.' ),
	'changed'   => array( 'title' => 'הסכום עודכן', 'text' => 'מאז שנשלח הקישור הסכום השתנה. זה הסכום העדכני לתשלום.' ),
	'returned'  => array( 'title' => 'קיבלנו את התשלום בטרנזילה', 'text' => 'אין צורך לשלם שוב.', 'icon' => array( 'ok', 'check' ) ),
	'paid'      => array( 'title' => 'התשלום כבר התקבל', 'text' => 'לא נדרשת פעולה נוספת. תודה.', 'icon' => array( 'ok', 'check' ) ),
	'checking'  => array( 'title' => 'בודקים את מצב התשלום', 'text' => 'זה לוקח רגע. כדי לא לחייב פעמיים, אפשר לנסות שוב בעוד כמה דקות.', 'icon' => array( 'wait', 'clock' ), 'wa' => true ),
	'on_hold'   => array( 'title' => 'נציג מטפל בנושא', 'text' => 'כרגע התשלום בבדיקה אצל הצוות שלנו. נחזור אליך בוואטסאפ.', 'icon' => array( 'wait', 'person' ), 'wa' => true ),
	'expired'   => array( 'title' => 'הקישור כבר לא בתוקף', 'text' => 'אפשר לכתוב לנו בוואטסאפ ונשלח קישור עדכני.', 'icon' => array( 'info', 'link' ), 'wa' => true ),
	'not_found' => array( 'title' => 'לא מצאנו את הקישור', 'text' => 'ייתכן שהקישור הועתק חלקית. אפשר לכתוב לנו בוואטסאפ ונשלח קישור חדש.', 'icon' => array( 'info', 'search' ), 'wa' => true ),
	'paused'    => array( 'title' => 'הדף לא זמין כרגע', 'text' => 'אפשר לנסות שוב בעוד כמה דקות, או לכתוב לנו בוואטסאפ.', 'icon' => array( 'info', 'pause' ), 'wa' => true ),
	'error'     => array( 'title' => 'משהו השתבש בדרך', 'text' => 'לא בוצע חיוב. אפשר לכתוב לנו בוואטסאפ ונעזור להשלים את התשלום.', 'icon' => array( 'info', 'info' ), 'wa' => true ),
);
$sc      = $screens[ $state ] ?? $screens['error'];
$payable = in_array( $state, array( 'ready', 'changed' ), true );
$can_pay = $payable && ! empty( $s['link_route']['allowed'] );
$items   = $payable ? (array) ( $s['items'] ?? array() ) : array();
$amount  = $payable ? (int) $s['amount_minor'] : 0;
$sent    = (int) ( $s['sent_minor'] ?? 0 );
$fmt     = static fn( int $minor ) => Money::format( $minor );
$row     = static fn( array $it ) => '<li><span>' . esc_html( $it['description'] ) . '</span><span>' . esc_html( $fmt( (int) $it['amount_minor'] ) . ' ' . $cur ) . '</span></li>';
$wa_btn  = static function () use ( $wa_url, $icons ) {
	if ( '' === $wa_url ) {
		return '<p class="note">אפשר להשיב להודעה שקיבלת מאיתנו בוואטסאפ.</p>';
	}
	return '<div class="actions"><a class="btn2" href="' . esc_url( $wa_url ) . '">' . $icons['chat'] . 'לכתוב לנו בוואטסאפ</a></div>';
};
?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?php echo $light ? '#F4F7FB' : '#05142F'; ?>">
<meta property="og:title" content="INSIDERS · תשלום מאובטח">
<meta property="og:description" content="עמוד התשלום המאובטח של INSIDERS">
<meta property="og:image" content="<?php echo esc_url( ICOL_URL . 'assets/brand/og-pay.png' ); ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<title>INSIDERS · תשלום</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* Tokens from INSIDERS Design System (tokens/colors.css, ui_kits/payment). #460FFF is the one spotlight: the pay button. */
html{background:#05142F}
.scr{--bg:#05142F;--card:rgba(239,244,248,.07);--line:rgba(239,244,248,.12);--fg:#fff;--fg2:rgba(255,255,255,.72);--fg3:rgba(255,255,255,.55);--label:#83CDFF;--wa:#0097FE;--ring:#83CDFF;--btn2-line:rgba(255,255,255,.25);--hl:#460FFF;--hl-h:#3A0BD6;--ok:#00C28A;--wait:#F5A623;
  margin:0;min-height:100vh;min-height:100dvh;background:radial-gradient(90% 40% at 100% 0%,rgba(70,15,255,.16),transparent 70%),var(--bg);background-color:var(--bg);color:var(--fg);font-family:Heebo,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;-webkit-font-smoothing:antialiased;display:flex;flex-direction:column;align-items:center}
.scr.light{--bg:#F4F7FB;--card:#fff;--line:#DCE3EC;--fg:#05142F;--fg2:#33455f;--fg3:#5b6675;--label:#004DAA;--wa:#004DAA;--ring:#004DAA;--btn2-line:#DCE3EC;--ok:#00915F;--wait:#B86E00;background:radial-gradient(90% 40% at 100% 0%,rgba(70,15,255,.07),transparent 70%),var(--bg)}
html:has(.scr.light){background:#F4F7FB}
.scr *{box-sizing:border-box}
.body{flex:1;width:100%;max-width:476px;display:flex;flex-direction:column;gap:18px;padding:22px 18px 18px;padding-top:max(22px,env(safe-area-inset-top));padding-bottom:max(18px,env(safe-area-inset-bottom))}
.brand{display:flex;justify-content:flex-start}
.brand img{height:17px;width:auto;display:block}
.brand .navy{display:none}.scr.light .brand .white{display:none}.scr.light .brand .navy{display:block}
.card{background:var(--card);border:1px solid var(--line);border-radius:24px;padding:26px 22px 22px;display:flex;flex-direction:column}
.scr.light .card{box-shadow:0 12px 32px -14px rgba(5,20,47,.18)}
.ttl{margin:0;font-size:26px;font-weight:900;line-height:1.2;text-wrap:balance}
.txt{margin:8px 0 0;font-size:16px;line-height:1.6;color:var(--fg2);text-wrap:pretty}
.note{margin:18px 0 0;font-size:15px;line-height:1.6;color:var(--fg2)}
.amt-wrap{margin-top:24px;padding-top:20px;border-top:1px solid var(--line)}
.lab{font-size:13px;font-weight:700;color:var(--label)}
.amt{display:flex;align-items:baseline;gap:6px;margin-top:2px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.amt .n{font-size:52px;letter-spacing:-.02em;direction:ltr}
.amt .c{font-size:28px;font-weight:800}
.prod{margin-top:10px;font-size:15px;color:var(--fg2)}
.was{margin-top:16px;display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:14px;background:rgba(131,205,255,.08);border:1px solid rgba(131,205,255,.22);font-size:14px;line-height:1.5;color:var(--fg2)}
.scr.light .was{background:rgba(0,77,170,.05);border-color:rgba(0,77,170,.18)}
.was svg{flex:none;width:18px;height:18px;color:var(--label);margin-top:1px}
.items{margin:18px 0 0;padding:0;list-style:none;border-top:1px solid var(--line)}
.items li,.more summary{display:flex;justify-content:space-between;align-items:center;gap:12px;min-height:44px;font-size:15px;border-bottom:1px solid var(--line)}
.items li span:last-child{font-variant-numeric:tabular-nums;color:var(--fg2);direction:ltr;white-space:nowrap}
.items li.tot{border-bottom:0;font-weight:800}.items li.tot span:last-child{color:var(--fg)}
.items .rest{display:contents}
.more summary{list-style:none;cursor:pointer;color:var(--wa);font-weight:600;justify-content:flex-start;gap:6px}
.more summary::-webkit-details-marker{display:none}
.more summary svg{width:16px;height:16px}
.more[open] summary{display:none}
.more .items{margin:0;border:0}
.actions{margin-top:24px;display:flex;flex-direction:column;gap:14px}
.btn{font:inherit;display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:56px;border-radius:999px;border:0;background:var(--hl);color:#fff;font-size:17px;font-weight:700;cursor:pointer;box-shadow:0 10px 34px -6px rgba(70,15,255,.5);transition:background 140ms}
.btn:hover{background:var(--hl-h)}
.btn svg{width:20px;height:20px}
.btn:focus-visible,.btn2:focus-visible,.help a:focus-visible,.foot a:focus-visible,.more summary:focus-visible{outline:3px solid var(--ring);outline-offset:3px}
.btn.is-loading,.btn[disabled]{background:var(--hl-h);cursor:progress;box-shadow:none}
.btn2{font:inherit;display:flex;align-items:center;justify-content:center;gap:10px;min-height:52px;border-radius:999px;background:transparent;color:var(--fg);border:1px solid var(--btn2-line);font-size:16px;font-weight:600;cursor:pointer;text-decoration:none}
.btn2:hover{background:var(--card);color:var(--fg)}
.btn2 svg{width:20px;height:20px;color:var(--wa)}
.trust{display:flex;gap:8px;align-items:flex-start;justify-content:center;font-size:13px;line-height:1.5;color:var(--fg3);text-align:center;text-wrap:balance}
.trust svg{flex:none;width:14px;height:14px;margin-top:3px}
.help{text-align:center;font-size:14px;color:var(--fg3)}
.help a{color:var(--wa);font-weight:600;text-decoration:none;display:inline-flex;align-items:center;min-height:44px}
.help a:hover{text-decoration:underline}
.ico{width:56px;height:56px;border-radius:50%;display:grid;place-items:center;margin-bottom:18px}
.ico svg{width:26px;height:26px}
.ico.ok{background:rgba(0,194,138,.14);color:var(--ok)}
.ico.wait{background:rgba(245,166,35,.14);color:var(--wait)}
.ico.info{background:rgba(131,205,255,.12);color:var(--label)}
.steps{margin:22px 0 0;padding:16px 0 0;list-style:none;border-top:1px solid var(--line);display:flex;flex-direction:column;gap:14px}
.steps li{display:flex;gap:12px;align-items:center;font-size:15px}
.steps i{flex:none;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;border:2px solid var(--line)}
.steps i svg{width:14px;height:14px}
.steps .done i{background:#00C28A;border-color:#00C28A;color:#05142F}
.steps .next i{border-color:#F5A623;border-style:dashed}
.steps .next{color:var(--fg2)}
.center{align-items:center;text-align:center}
.spin{width:44px;height:44px;border-radius:50%;border:3px solid var(--line);border-top-color:var(--label);animation:sp 1s linear infinite;margin:20px 0 22px}
.btn .spin{width:20px;height:20px;border-width:2px;border-color:rgba(255,255,255,.3);border-top-color:#fff;margin:0}
@keyframes sp{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.spin{animation:none;opacity:.8}}
/* Interstitial after the click: same card, the pay content steps aside (the form stays in the DOM so the POST completes). */
.leaving{display:none}
.card.is-leaving{align-items:center;text-align:center}
.card.is-leaving > :not(.leaving){display:none}
.card.is-leaving .leaving{display:flex;flex-direction:column;align-items:center}
.scr.is-leaving .help{visibility:hidden}
.foot{margin-top:auto;padding-top:8px;display:flex;justify-content:center;gap:16px;font-size:13px}
.foot a{color:var(--fg3);display:inline-flex;align-items:center;min-height:44px;text-decoration:none}
@media (min-width:600px){.body{padding-top:56px}.brand{justify-content:center}.brand img{height:20px}.card{padding:34px 32px 28px}}
</style>
</head>
<body class="scr<?php echo $light ? ' light' : ''; ?>">
<div class="body">
	<div class="brand"><img class="white" src="<?php echo esc_url( ICOL_URL . 'assets/brand/logo-white.svg' ); ?>" alt="INSIDERS" width="54" height="17"><img class="navy" src="<?php echo esc_url( ICOL_URL . 'assets/brand/logo-navy.svg' ); ?>" alt="INSIDERS" width="54" height="17"></div>
	<main class="card" id="card" role="<?php echo $payable ? 'main' : 'status'; ?>" aria-live="polite">
		<?php if ( ! empty( $sc['icon'] ) ) : ?>
		<div class="ico <?php echo esc_attr( $sc['icon'][0] ); ?>" aria-hidden="true"><?php echo $icons[ $sc['icon'][1] ]; // phpcs:ignore -- static markup ?></div>
		<?php endif; ?>
		<h1 class="ttl"><?php echo esc_html( $sc['title'] ); ?></h1>
		<p class="txt"><?php echo esc_html( $sc['text'] ); ?></p>

		<?php if ( 'returned' === $state ) : ?>
		<ul class="steps">
			<li class="done"><i aria-hidden="true"><?php echo $icons['check']; // phpcs:ignore ?></i>התשלום נקלט בטרנזילה</li>
			<li class="next"><i aria-hidden="true"></i>אישור סופי יישלח אליך בוואטסאפ</li>
		</ul>
		<?php endif; ?>

		<?php if ( $payable ) : ?>
		<div class="amt-wrap">
			<div class="lab">לתשלום</div>
			<div class="amt" aria-label="<?php echo esc_attr( $fmt( $amount ) . ' ' . ( '₪' === $cur ? 'שקלים' : $cur ) ); ?>"><span class="n"><?php echo esc_html( $fmt( $amount ) ); ?></span><span class="c"><?php echo esc_html( $cur ); ?></span></div>
			<?php if ( 1 === count( $items ) ) : ?><div class="prod"><?php echo esc_html( $items[0]['description'] ); ?></div><?php endif; ?>
		</div>
		<?php if ( 'changed' === $state && $sent > 0 && $sent !== $amount ) : ?>
		<div class="was"><?php echo $icons['info']; // phpcs:ignore ?><span><?php echo esc_html( 'בהודעה שקיבלת הופיע סכום של ' . $fmt( $sent ) . ' ' . $cur . '. ' . ( $sent > $amount ? 'חלק מהתשלום כבר התקבל.' : 'מאז נוסף תשלום שהגיע מועדו.' ) ); ?></span></div>
		<?php endif; ?>
		<?php if ( count( $items ) > 1 ) : ?>
		<ul class="items">
			<?php
			// Up to five rows show in full; six and more show four and fold the rest (works without JavaScript).
			$visible = count( $items ) > 5 ? array_slice( $items, 0, 4 ) : $items;
			$rest    = count( $items ) > 5 ? array_slice( $items, 4 ) : array();
			foreach ( $visible as $it ) {
				echo $row( $it ); // phpcs:ignore -- escaped in $row
			}
			if ( $rest ) :
				?>
			<li class="rest"><details class="more"><summary><?php echo $icons['chev']; // phpcs:ignore ?>עוד <?php echo (int) count( $rest ); ?> תשלומים</summary><ul class="items"><?php foreach ( $rest as $it ) { echo $row( $it ); } // phpcs:ignore ?></ul></details></li>
			<?php endif; ?>
			<li class="tot"><span>סה״כ</span><span><?php echo esc_html( $fmt( $amount ) . ' ' . $cur ); ?></span></li>
		</ul>
		<?php endif; ?>
		<?php endif; // payable ?>

		<?php if ( $can_pay ) : ?>
		<form class="actions" method="post" action="<?php echo esc_url( $action ); ?>" id="pay-form">
			<input type="hidden" name="t" value="<?php echo esc_attr( $form_token ); ?>">
			<button class="btn" type="submit" id="pay-go">להמשך לתשלום מאובטח<?php echo $icons['arrow']; // phpcs:ignore ?></button>
			<div class="trust"><?php echo $icons['lock']; // phpcs:ignore ?><span>התשלום מתבצע בעמוד המאובטח של טרנזילה. פרטי הכרטיס לא נשמרים אצלנו.</span></div>
		</form>
		<div class="leaving" aria-hidden="true">
			<div class="spin"></div>
			<h1 class="ttl">מעבירים לעמוד התשלום המאובטח</h1>
			<p class="txt">של טרנזילה, חברת הסליקה שלנו. זה לוקח כמה שניות.</p>
		</div>
		<?php elseif ( $payable ) : ?>
			<p class="note">כרגע אין תשלום מקוון לפריט הזה. נשמח לסדר את זה איתך בוואטסאפ.</p>
			<?php echo $wa_btn(); // phpcs:ignore -- escaped inside ?>
		<?php elseif ( ! empty( $sc['wa'] ) ) : ?>
			<?php echo $wa_btn(); // phpcs:ignore -- escaped inside ?>
		<?php endif; ?>
	</main>
	<?php if ( $can_pay && '' !== $wa_url ) : ?>
	<div class="help">שאלה על התשלום? <a href="<?php echo esc_url( $wa_url ); ?>">לכתוב לנו בוואטסאפ</a></div>
	<?php endif; ?>
	<?php if ( '' !== $a11y_url ) : ?>
	<div class="foot"><a href="<?php echo esc_url( $a11y_url ); ?>">הצהרת נגישות</a></div>
	<?php endif; ?>
</div>
<?php if ( $can_pay ) : ?>
<script nonce="<?php echo esc_attr( $nonce ); ?>">
// One click only. The page works without this script; it only locks the button and shows the short interstitial.
(function () {
	var form = document.getElementById('pay-form'), btn = document.getElementById('pay-go'), card = document.getElementById('card');
	var label = btn.innerHTML;
	form.addEventListener('submit', function (e) {
		if (btn.getAttribute('aria-busy') === 'true') { e.preventDefault(); return; }
		btn.setAttribute('aria-busy', 'true');
		btn.classList.add('is-loading');
		btn.innerHTML = '<span class="spin" aria-hidden="true"></span>מעבירים לטרנזילה';
		setTimeout(function () { card.classList.add('is-leaving'); document.body.classList.add('is-leaving'); card.querySelector('.leaving').removeAttribute('aria-hidden'); }, 150);
	});
	// Back from Tranzila through the browser cache: show the card again, not a frozen spinner.
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted) { return; }
		btn.removeAttribute('aria-busy'); btn.classList.remove('is-loading'); btn.innerHTML = label;
		card.classList.remove('is-leaving'); document.body.classList.remove('is-leaving'); card.querySelector('.leaving').setAttribute('aria-hidden', 'true');
	});
})();
</script>
<?php endif; ?>
</body>
</html>
