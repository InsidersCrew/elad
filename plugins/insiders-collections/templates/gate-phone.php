<?php
/**
 * Phone side of the scan gate. Internal page (staff only), same visual language as
 * the pay page. Variables in scope: $config (root, id, secure).
 */
defined( 'ABSPATH' ) || exit;
$nonce = \Insiders\Collections\Front\GatePage::$nonce;
?><!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>INSIDERS · כניסה למערכת התשלומים</title>
<link href="https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;700;900&display=swap" rel="stylesheet">
<style>
html{background:#05142F}
.icolg{--bg:#05142F;--hl:#460FFF;--hl-h:#3A0BD6;--sky:#83CDFF;--g07:rgba(239,244,248,.07);--g12:rgba(239,244,248,.12);--t2:rgba(255,255,255,.72);--t3:rgba(255,255,255,.48);--ok:#00C28A;--bad:#ff8a87;margin:0;min-height:100vh;background:radial-gradient(120% 60% at 85% 0%,rgba(70,15,255,.16),transparent 60%),var(--bg);color:#fff;font-family:Heebo,system-ui,sans-serif;display:flex;justify-content:center;padding:28px 16px 40px;box-sizing:border-box}
.icolg *{box-sizing:border-box}
.icolg-card{width:100%;max-width:420px;background:var(--g07);border:1px solid var(--g12);border-radius:24px;padding:26px 20px}
.icolg-logo{margin:0 0 22px;display:flex;justify-content:flex-start}
.icolg-logo img{height:17px;width:auto;display:block}
.icolg h1{font-size:24px;font-weight:800;margin:0 0 6px;line-height:1.25}
.icolg p{color:var(--t2);margin:0 0 16px;font-size:16px;line-height:1.55}
.icolg-meta{background:rgba(0,0,0,.18);border:1px solid var(--g12);border-radius:14px;padding:12px 14px;margin:0 0 18px;font-size:14px;color:var(--t2);line-height:1.7}
.icolg-meta strong{color:#fff;font-weight:600}
.icolg-nums{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:8px 0 4px}
.icolg-num{height:76px;border-radius:18px;border:1px solid var(--g12);background:rgba(239,244,248,.06);color:#fff;font:800 30px Heebo,sans-serif;font-variant-numeric:tabular-nums;cursor:pointer}
.icolg-num:active,.icolg-num:hover{background:var(--hl);border-color:var(--hl)}
.icolg-btn{display:block;width:100%;margin-top:16px;padding:16px;border:0;border-radius:999px;background:var(--hl);color:#fff;font:700 17px Heebo,sans-serif;cursor:pointer}
.icolg-btn:hover{background:var(--hl-h)}
.icolg-btn[disabled]{opacity:.7}
.icolg button:focus-visible,.icolg input:focus-visible{outline:3px solid var(--sky);outline-offset:3px}
.icolg label{display:block;color:var(--t2);font-size:14px;margin:0 0 6px}
.icolg input{width:100%;height:48px;border-radius:12px;border:1px solid rgba(239,244,248,.22);background:rgba(0,0,0,.2);color:#fff;padding:0 14px;font:500 16px Heebo,sans-serif}
.icolg-code{font-size:44px;font-weight:900;letter-spacing:6px;text-align:center;font-variant-numeric:tabular-nums;direction:ltr;margin:10px 0 6px}
.icolg-state{width:48px;height:48px;border-radius:50%;display:grid;place-items:center;margin:0 0 14px;font-size:24px;background:var(--g12)}
.icolg-state.ok{background:rgba(0,194,138,.18);color:var(--ok)}
.icolg-state.bad{background:rgba(229,57,53,.16);color:var(--bad)}
.icolg-err{color:var(--bad);font-size:15px;margin:12px 0 0;min-height:1em}
.icolg-foot{color:var(--t3);font-size:12px;margin-top:20px;line-height:1.6;text-align:center}
</style>
</head>
<body class="icolg">
<main class="icolg-card" id="icolg" aria-live="polite">
	<div class="icolg-logo"><img src="<?php echo esc_url( ICOL_URL . 'assets/brand/logo-white.svg' ); ?>" alt="INSIDERS" width="54" height="17"></div>
	<h1>רגע…</h1>
</main>
<script nonce="<?php echo esc_attr( $nonce ); ?>">window.ICOL_GATE_PHONE = <?php echo wp_json_encode( $config ); ?>;</script>
<script nonce="<?php echo esc_attr( $nonce ); ?>" src="<?php echo esc_url( ICOL_URL . 'assets/gate-phone.js?ver=' . ICOL_VERSION ); ?>"></script>
</body>
</html>
