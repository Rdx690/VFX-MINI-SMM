<?php
declare(strict_types=1);

/**
 * Shared FamGateway helpers. This file must only be included by server-side PHP endpoints.
 * Wallet balance and idempotency ledger are written together under one RTDB ETag compare-and-set.
 */
function fg_reply(int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function fg_firebase(array $c, string $path, string $method = 'GET', ?array $body = null, array $extraHeaders = []): array {
    $token = (string)($c['firebase_admin_token'] ?? '');
    $base = rtrim((string)($c['firebase_database_url'] ?? ''), '/');
    if ($token === '' || $base === '') throw new RuntimeException('Firebase server credentials are not configured');
    $url = $base . '/' . ltrim($path, '/') . '.json?auth=' . rawurlencode($token);
    $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $extraHeaders);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Firebase network error: ' . $error);
    if ($code < 200 || $code >= 300) throw new RuntimeException('Firebase request failed (' . $code . ')');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['value' => $decoded];
}

/**
 * Reserve a transaction reference globally so one UTR cannot be used to fund two panel accounts.
 * The hashed key avoids storing a raw UTR in the Firebase path. A conditional create arbitrates races.
 */
function fg_claim_utr(array $c, string $utr, string $orderId, string $uid, float $amount): void {
    $key = hash('sha256', strtoupper(trim($utr)));
    $path = 'famgateway_utrs/' . $key;
    $existing = fg_firebase($c, $path);
    $record = (array_key_exists('value', $existing) && $existing['value'] === null) ? null : $existing;
    if (is_array($record)) {
        if (($record['order_id'] ?? '') !== $orderId || ($record['uid'] ?? '') !== $uid) {
            throw new RuntimeException('Transaction reference has already been used for another order');
        }
        return;
    }

    $token = (string)$c['firebase_admin_token'];
    $url = rtrim((string)$c['firebase_database_url'], '/') . '/' . $path . '.json?auth=' . rawurlencode($token);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => json_encode([
            'order_id' => $orderId,
            'uid' => $uid,
            'amount' => number_format($amount, 2, '.', ''),
            'claimed_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'If-Match: null_etag'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw !== false && $code >= 200 && $code < 300) return;

    // Another request may have claimed the same UTR between our read and conditional write.
    if ($code === 412) {
        $after = fg_firebase($c, $path);
        $claimed = (array_key_exists('value', $after) && $after['value'] === null) ? null : $after;
        if (is_array($claimed) && ($claimed['order_id'] ?? '') === $orderId && ($claimed['uid'] ?? '') === $uid) return;
        throw new RuntimeException('Transaction reference was claimed by another order');
    }
    throw new RuntimeException('Unable to reserve transaction reference: ' . $code . ' ' . $error);
}

/**
 * Credit exactly once per order. A compare-and-set on the whole user record prevents two
 * concurrent requests for the same order from both increasing the balance. The order status
 * update happens after the CAS; a retry safely repairs it from the embedded credit ledger.
 */
function fg_credit_wallet(array $c, string $uid, string $orderId, float $amount, string $utr, string $transactionId = ''): array {
    if ($uid === '' || $orderId === '' || $utr === '' || !is_finite($amount) || $amount <= 0) {
        throw new RuntimeException('Invalid wallet credit arguments');
    }
    fg_claim_utr($c, $utr, $orderId, $uid, $amount);
    $base = rtrim((string)$c['firebase_database_url'], '/');
    $token = (string)$c['firebase_admin_token'];
    if ($base === '' || $token === '') throw new RuntimeException('Firebase server credentials are not configured');
    $userUrl = $base . '/users/' . rawurlencode($uid) . '.json?auth=' . rawurlencode($token);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $responseHeaders = [];
        $read = curl_init($userUrl);
        curl_setopt_array($read, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Firebase-ETag: true'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return $length;
            },
        ]);
        $raw = curl_exec($read);
        $code = (int)curl_getinfo($read, CURLINFO_HTTP_CODE);
        $error = curl_error($read);
        curl_close($read);
        if ($raw === false || $code !== 200) throw new RuntimeException('Unable to read wallet for credit: ' . $code . ' ' . $error);
        $etag = (string)($responseHeaders['etag'] ?? '');
        $user = json_decode($raw, true);
        if ($etag === '' || !is_array($user)) throw new RuntimeException('Wallet record or Firebase ETag is invalid');

        $ledger = is_array($user['famgatewayCredits'] ?? null) ? $user['famgatewayCredits'] : [];
        if (isset($ledger[$orderId])) {
            $existing = $ledger[$orderId];
            if (($existing['utr'] ?? '') !== $utr || abs((float)($existing['amount'] ?? 0) - $amount) > 0.001) {
                throw new RuntimeException('Order already exists in wallet ledger with conflicting details');
            }
            fg_firebase($c, 'famgateway_orders/' . rawurlencode($orderId), 'PATCH', [
                'status' => 'completed',
                'utr' => $utr,
                'transaction_id' => $transactionId,
                'verified_at' => gmdate('c'),
            ]);
            return ['credited' => false, 'already_credited' => true];
        }

        $balance = filter_var($user['balance'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($balance === false || !is_finite((float)$balance)) throw new RuntimeException('Existing wallet balance is invalid');
        $user['balance'] = round((float)$balance + $amount, 2);
        $ledger[$orderId] = [
            'order_id' => $orderId,
            'amount' => number_format($amount, 2, '.', ''),
            'utr' => $utr,
            'transaction_id' => $transactionId,
            'gateway' => 'FamGateway',
            'credited_at' => gmdate('c'),
        ];
        $user['famgatewayCredits'] = $ledger;

        $write = curl_init($userUrl);
        curl_setopt_array($write, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => json_encode($user, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'If-Match: ' . $etag],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $writeRaw = curl_exec($write);
        $writeCode = (int)curl_getinfo($write, CURLINFO_HTTP_CODE);
        $writeError = curl_error($write);
        curl_close($write);
        if ($writeCode === 412) continue;
        if ($writeRaw === false || $writeCode < 200 || $writeCode >= 300) {
            throw new RuntimeException('Conditional wallet update failed: ' . $writeCode . ' ' . $writeError);
        }

        fg_firebase($c, 'famgateway_orders/' . rawurlencode($orderId), 'PATCH', [
            'status' => 'completed',
            'utr' => $utr,
            'transaction_id' => $transactionId,
            'verified_at' => gmdate('c'),
        ]);
        return ['credited' => true, 'already_credited' => false];
    }
    throw new RuntimeException('Wallet changed repeatedly; retry the payment verification');
}
