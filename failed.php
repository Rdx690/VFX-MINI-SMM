<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$orderId = (string)($_GET['orderid'] ?? '');
$orderId = preg_match('/^[A-Za-z0-9_-]{1,100}$/', $orderId) ? $orderId : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#eef6ff">
<title>Payment not completed • VFX MINI SMM</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;font-family:-apple-system,BlinkMacSystemFont,"SF Pro Display",Inter,system-ui,sans-serif;color:#19243b;background:radial-gradient(ellipse at 10% 10%,#d5edff,transparent 40%),radial-gradient(ellipse at 90% 90%,#e0dcff,transparent 40%),linear-gradient(145deg,#f9fcff,#edf5ff)}.card{width:min(100%,440px);padding:30px;border:1px solid #ffffffd9;border-radius:30px;background:#ffffff9c;backdrop-filter:blur(28px) saturate(150%);-webkit-backdrop-filter:blur(28px) saturate(150%);box-shadow:0 24px 70px #4b67971c;text-align:center}.icon{width:76px;height:76px;margin:0 auto 18px;display:grid;place-items:center;border-radius:25px;background:linear-gradient(145deg,#fff,#fff0ed);color:#d55d57;font-size:32px}.tag{font-size:10px;font-weight:900;letter-spacing:1.4px;color:#687ba5}.card h1{font-size:25px;letter-spacing:-.7px;margin:10px 0}.card p{font-size:13px;line-height:1.75;color:#71809a;margin:0 0 20px}.order{padding:12px;border-radius:13px;background:#ffffff91;border:1px solid #ffffff;font-size:11px;color:#66748e;overflow-wrap:anywhere;margin-bottom:20px}.btn{display:flex;justify-content:center;align-items:center;padding:15px;border-radius:16px;background:linear-gradient(135deg,#6084f7,#5967e8);color:white;font-weight:800;font-size:13px;text-decoration:none;box-shadow:0 12px 25px #5e77e83b}.hint{margin-top:15px;font-size:11px;color:#8390a7;line-height:1.6}
</style></head><body><main class="card"><div class="icon">!</div><div class="tag">VFX MINI SMM · FAMGATEWAY</div><h1>Payment status needs checking</h1><p>This return page cannot decide whether your payment failed. Please check the live status from Add Funds. If your UPI app says you paid, do not pay again until the status is resolved.</p><?php if ($orderId !== ''): ?><div class="order">Order ID: <strong><?= htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') ?></strong></div><?php endif; ?><a class="btn" href="addfunds.html">Back to Add Funds</a><div class="hint">Keep your order ID if you need support.</div></main></body></html>
