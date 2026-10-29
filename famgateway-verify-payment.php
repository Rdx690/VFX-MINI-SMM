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

    // Atomic idempotent credit: update the complete user node using Firebase RTDB ETag/If-Match.
    // The wallet balance and per-user credit ledger live in the same node, so concurrent requests
    // cannot both credit the same order. Retry conflicts by re-reading the latest user node.
    $base = rtrim((string)$config['firebase_database_url'], '/');
    $token = (string)$config['firebase_admin_token'];
    $userUrl = $base . '/users/' . rawurlencode($uid) . '.json?auth=' . rawurlencode($token);
    $credited = false;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $read = curl_init($userUrl);
        curl_setopt_array($read, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPGET=>true,
            CURLOPT_HTTPHEADER=>['X-Firebase-ETag: true'],
            CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2
        ]);
        $userRaw = curl_exec($read);
        $readCode = (int)curl_getinfo($read, CURLINFO_HTTP_CODE);
        $etag = (string)curl_getinfo($read, CURLINFO_HEADER_OUT);
        $headers = [];
        curl_close($read);
        if ($userRaw === false || $readCode !== 200) throw new RuntimeException('Unable to read wallet record for atomic credit');

        // Read ETag separately with a header callback.
        $etagRead = curl_init($userUrl);
        curl_setopt_array($etagRead, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPGET=>true,
            CURLOPT_HEADER=>true, CURLOPT_HTTPHEADER=>['X-Firebase-ETag: true'],
            CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2
        ]);
        $etagResponse = curl_exec($etagRead);
        $etagCode = (int)curl_getinfo($etagRead, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($etagRead, CURLINFO_HEADER_SIZE);
        curl_close($etagRead);
        if ($etagResponse === false || $etagCode !== 200) throw new RuntimeException('Unable to obtain wallet concurrency token');
        $etagHeaders = substr($etagResponse, 0, $headerSize);
        if (!preg_match('/^ETag:\\s*(.+)\\s*$/mi', $etagHeaders, $etagMatch)) throw new RuntimeException('Firebase did not return an ETag');
        $etagValue = trim($etagMatch[1]);
        $userNode = json_decode(substr($etagResponse, $headerSize), true);
        if (!is_array($userNode)) throw new RuntimeException('Wallet record is invalid');

        $credits = is_array($userNode['famgatewayCredits'] ?? null) ? $userNode['famgatewayCredits'] : [];
        if (isset($credits[$orderId])) {
            if (($credits[$orderId]['utr'] ?? '') !== $utr || abs((float)($credits[$orderId]['amount'] ?? 0) - $expectedAmount) > 0.001) {
                reply(409, ['status'=>'error','message'=>'Order was already credited with conflicting details']);
            }
            $credited = true;
            break;
        }
        $currentBalance = filter_var($userNode['balance'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($currentBalance === false || !is_finite((float)$currentBalance)) throw new RuntimeException('Existing wallet balance is invalid');
        $userNode['balance'] = round((float)$currentBalance + $expectedAmount, 2);
        $userNode['famgatewayCredits'] = $credits;
        $userNode['famgatewayCredits'][$orderId] = [
            'order_id'=>$orderId, 'amount'=>number_format($expectedAmount, 2, '.', ''),
            'utr'=>$utr, 'gateway'=>'FamGateway', 'credited_at'=>gmdate('c')
        ];

        $write = curl_init($userUrl);
        curl_setopt_array($write, [
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'PUT',
            CURLOPT_POSTFIELDS=>json_encode($userNode, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'If-Match: '.$etagValue],
            CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2
        ]);
        $writeBody = curl_exec($write);
        $writeCode = (int)curl_getinfo($write, CURLINFO_HTTP_CODE);
        curl_close($write);
        if ($writeCode === 412) continue;
        if ($writeBody === false || $writeCode < 200 || $writeCode >= 300) throw new RuntimeException('Atomic wallet update failed');
        $credited = true;
        break;
    }
    if (!$credited) reply(409, ['status'=>'retry','message'=>'Wallet changed concurrently. Retry payment verification.','order_id'=>$orderId]);

    fb($config, 'famgateway_orders/'.$orderId, 'PATCH', [
        'status'=>'completed', 'utr'=>$utr, 'transaction_id'=>(string)($data['transaction_id']??''),
        'verified_at'=>gmdate('c')
    ]);
    reply(200, ['status'=>'success','message'=>'Payment verified and wallet credited','order_id'=>$orderId,'amount'=>number_format($expectedAmount,2,'.','')]);
} catch(Throwable $e) {
    error_log('[FamGateway verify payment] '.$e->getMessage());
    reply(500,['status'=>'error','message'=>'Unable to verify payment right now. Try again shortly.']);
}
