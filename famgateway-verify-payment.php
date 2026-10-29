<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$config = require __DIR__ . '/famgateway.config.php';

function reply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
function fb(array $c, string $path, string $method='GET', ?array $body=null): array {
    if (empty($c['firebase_admin_token'])) throw new RuntimeException('Firebase Admin credential is not configured');
    $url = rtrim((string)$c['firebase_database_url'],'/') . '/' . ltrim($path,'?') . '.json?auth=' . rawurlencode((string)$c['firebase_admin_token']);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_SLASHES));
    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($raw===false || $status<200 || $status>=300) throw new RuntimeException('Firebase request failed: '.$status.' '.$err);
    $data=json_decode($raw,true);
    return is_array($data)?$data:['value'=>$data];
}
function verifyGatewayOrder(array $c,string $orderId): array {
    if(empty($c['api_key'])) throw new RuntimeException('FamGateway API key is not configured');
    $url='https://famgateway.in/api/verify-order.php?order_id='.rawurlencode($orderId);
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Accept: application/json','X-Api-Key: '.$c['api_key']],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($raw===false || $status<200 || $status>=300) throw new RuntimeException('Gateway verification failed: '.$status.' '.$err);
    $decoded=json_decode($raw,true); if(!is_array($decoded)) throw new RuntimeException('Invalid gateway verification JSON');
    return $decoded;
}
try {
    if($_SERVER['REQUEST_METHOD']!=='POST') reply(405,['status'=>'error','message'=>'POST required']);
    $input=json_decode(file_get_contents('php://input') ?: '',true);
    if(!is_array($input)) reply(400,['status'=>'error','message'=>'Invalid JSON']);
    $orderId=preg_replace('/[^A-Za-z0-9_-]/','',(string)($input['order_id']??''));
    if($orderId==='' || strlen($orderId)>100) reply(422,['status'=>'error','message'=>'Invalid order ID']);
    if(empty($_SERVER['HTTP_AUTHORIZATION']) || !preg_match('/^Bearer\s+(.+)$/i',trim($_SERVER['HTTP_AUTHORIZATION']),$m)) reply(401,['status'=>'error','message'=>'Firebase ID token required']);
    $webKey=getenv('FIREBASE_WEB_API_KEY') ?: '';
    if($webKey==='') reply(500,['status'=>'error','message'=>'FIREBASE_WEB_API_KEY is not configured']);
    $ch=curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key='.rawurlencode($webKey));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['idToken'=>$m[1]]),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $identity=json_decode((string)$raw,true); $firebaseUser=$identity['users'][0]??null;
    if($code!==200 || !is_array($firebaseUser) || empty($firebaseUser['localId'])) reply(401,['status'=>'error','message'=>'Invalid Firebase session']);
    $uid=(string)$firebaseUser['localId'];

    $order=fb($config,'famgateway_orders/'.$orderId);
    if(!is_array($order) || ($order['uid']??'')!==$uid) reply(404,['status'=>'error','message'=>'Order not found for this account']);
    if(($order['status']??'')==='completed') reply(200,['status'=>'success','message'=>'Payment already credited','order_id'=>$orderId]);
    $verification=verifyGatewayOrder($config,$orderId);
    $data=$verification['data']??$verification;
    $gatewayStatus=strtolower((string)($data['status']??$verification['status']??''));
    if(!in_array($gatewayStatus,['success','paid','completed'],true)) {
        reply(200,['status'=>'pending','message'=>($gatewayStatus==='expired'?'Payment session expired':'Payment is not confirmed yet'),'order_id'=>$orderId]);
    }
    $paidAmount=filter_var($data['amount']??$data['payable_amount']??null,FILTER_VALIDATE_FLOAT);
    $expectedAmount=(float)($order['amount']??0);
    if($paidAmount===false || abs((float)$paidAmount-$expectedAmount)>0.001) reply(409,['status'=>'error','message'=>'Verified amount does not match the stored order']);
    $utr=trim((string)($data['utr']??$data['transaction_id']??''));
    if($utr==='') reply(409,['status'=>'error','message'=>'Gateway confirmed payment without a transaction reference']);

    // SECURITY NOTE: RTDB REST cannot atomically increment multiple separate records.
    // Use Firebase Admin SDK / Cloud Function transaction in production for strict concurrency.
    // This endpoint stores a pending credit request but intentionally does not credit balance
    // unless the deployment supplies a trusted atomic ledger implementation.
    reply(503,['status'=>'needs_atomic_ledger','message'=>'Gateway payment is verified, but automatic balance credit is disabled until an atomic, idempotent ledger adapter is configured. Contact admin with order ID '.$orderId]);
} catch(Throwable $e) {
    error_log('[FamGateway verify payment] '.$e->getMessage());
    reply(500,['status'=>'error','message'=>'Unable to verify payment right now. Try again shortly.']);
}
