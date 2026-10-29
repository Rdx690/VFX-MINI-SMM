<?php
declare(strict_types=1);
// This return URL is informational only. Never trust query-string amount/order/user fields as payment proof.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#eef6ff"><title>Payment status • VFX MINI SMM</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display",Inter,system-ui,sans-serif;color:#19243b;background:radial-gradient(ellipse at 10% 10%,#d5edff,transparent 40%),radial-gradient(ellipse at 90% 90%,#e0dcff,transparent 40%),linear-gradient(145deg,#f9fcff,#edf5ff)}.card{width:min(100%,440px);padding:30px;border:1px solid #ffffffd9;border-radius:30px;background:#ffffff9c;backdrop-filter:blur(28px) saturate(150%);-webkit-backdrop-filter:blur(28px) saturate(150%);box-shadow:0 24px 70px #4b67971c;text-align:center}.icon{width:76px;height:76px;margin:0 auto 18px;display:grid;place-items:center;border-radius:25px;background:linear-gradient(145deg,#fff,#e9efff);color:#5978ee;font-size:32px;box-shadow:0 10px 25px #5978ee20}.tag{font-size:10px;font-weight:900;letter-spacing:1.4px;color:#687ba5}.card h1{font-size:25px;letter-spacing:-.7px;margin:10px 0}.card p{font-size:13px;line-height:1.75;color:#71809a;margin:0 0 22px}.btn{display:flex;justify-content:center;align-items:center;padding:15px;border-radius:16px;background:linear-gradient(135deg,#6084f7,#5967e8);color:white;font-weight:800;font-size:13px;text-decoration:none;box-shadow:0 12px 25px #5e77e83b}.hint{margin-top:15px;font-size:11px;color:#8390a7;line-height:1.6}@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}
</style></head><body><main class="card"><div class="icon">↗</div><div class="tag">VFX MINI SMM · FAMGATEWAY</div><h1>Check your payment status</h1><p>Returning from checkout does not confirm a successful payment. Open Add Funds and use <strong>Check payment status</strong>. Your wallet is credited only after the server independently verifies the order.</p><a class="btn" href="addfunds.html">Return to Add Funds</a><div class="hint">If you already paid, please don't pay again while the status is pending. Keep your order ID for support.</div></main></body></html>
