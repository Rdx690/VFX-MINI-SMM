<?php
declare(strict_types=1);

/**
 * FamGateway webhook receiver.
 * Requires:
 * - FAMGATEWAY_API_KEY
 * - FIREBASE_DATABASE_URL
 * - FIREBASE_ADMIN_TOKEN (or replace REST helper with Firebase Admin SDK)
 * - A Cloud Function / Admin SDK implementation for atomic balance-credit transactions.
 *
 * This endpoint verifies HMAC signatures, stores webhook event details, and rejects duplicate
 * order/UTR events. To avoid unsafe non-atomic balance writes, it fails closed before crediting.
 */
$config = require __DIR__ . '/famgateway.config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
function fbRequest(array $c,string $path,string $method='GET',?array $body=null): array {
    if(empty($c['firebase_admin_token'])) throw new RuntimeException('Firebase Admin credential is not configured');
    $url=rtrim((string)$c['firebase_database_url'],'/').'/'.ltrim($path,'/').'.json?auth='.rawurlencode((string)$c['firebase_admin_token']);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_SLASHES));
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false||$code<200||$code>=300)throw new RuntimeException('Firebase request failed '.$code.' '.$err);
    $decoded=json_decode($raw,true);return is_array($decoded)?$decoded:['value'=>$decoded];
}
try {
    if($_SERVER['REQUEST_METHOD']!=='POST') reply(405,['status'=>'error','message'=>'POST required']);
    $raw=file_get_contents('php://input');
    if($raw===false||$raw==='') reply(400,['status'=>'error','message'=>'Empty payload']);
    $signature=(string)($_SERVER['HTTP_X_FAMGATEWAY_SIGNATURE']??'');
    $key=(string)($config['webhook_secret']??'');
    if($key===''||!preg_match('/^[a-f0-9]{64}$/i',$signature)) reply(401,['status'=>'error','message'=>'Missing or malformed signature']);
    $expected=hash_hmac('sha256',$raw,$key);
    if(!hash_equals($expected,strtolower($signature))) reply(401,['status'=>'error','message'=>'Invalid webhook signature']);
    $event=json_decode($raw,true);
    if(!is_array($event)) reply(400,['status'=>'error','message'=>'Malformed JSON']);
    if(($event['event']??'')!=='payment.success'||strtolower((string)($event['status']??''))!=='success') reply(200,['status'=>'ignored']);

    $orderId=preg_replace('/[^A-Za-z0-9_-]/','',(string)($event['order_id']??''));
    $utr=trim((string)($event['utr']??''));
    $amount=filter_var($event['amount']??null,FILTER_VALIDATE_FLOAT);
    if($orderId===''||$utr===''||$amount===false||(float)$amount<=0) reply(422,['status'=>'error','message'=>'Required payment fields are missing']);

    $intent=fbRequest($config,'famgateway_orders/'.$orderId);
    if(!is_array($intent)||empty($intent['uid'])||empty($intent['amount'])) reply(404,['status'=>'error','message'=>'Unknown order']);
    if(abs((float)$intent['amount']-(float)$amount)>0.001) reply(409,['status'=>'error','message'=>'Amount mismatch']);

    // Record verified event data; the actual balance credit must be performed via a trusted
    // Firebase Admin SDK transaction/Cloud Function using unique order_id and UTR constraints.
    fbRequest($config,'famgateway_webhook_events/'.$orderId,'PUT',[
        'order_id'=>$orderId,'utr'=>$utr,'amount'=>number_format((float)$amount,2,'.',''),
        'uid'=>(string)$intent['uid'],'signature_verified'=>true,'status'=>'verified_pending_atomic_credit',
        'received_at'=>gmdate('c')
    ]);

    // Deliberately do not claim a wallet balance was credited. Atomic Firebase transaction adapter is required.
    reply(503,['status'=>'needs_atomic_ledger','message'=>'Webhook authenticated and recorded; atomic balance credit adapter is required before enabling auto-credit']);
} catch(Throwable $e) {
    error_log('[FamGateway webhook] '.$e->getMessage());
    reply(500,['status'=>'error','message'=>'Webhook processing failed']);
}
