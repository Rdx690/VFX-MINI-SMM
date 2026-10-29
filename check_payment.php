<?php
declare(strict_types=1);

/**
 * LEGACY DISABLED: this endpoint trusted client-supplied amount/user IDs and old gateway status.
 * Keep the path as a hard stop so old Add Funds pages cannot credit wallets via this route.
 * New UI calls famgateway-create-order.php and famgateway-verify-payment.php.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
http_response_code(410);
echo json_encode([
    'status' => 'disabled',
    'message' => 'Legacy payment verification has been disabled. Please reload Add Funds and create a new FamGateway payment order.'
], JSON_UNESCAPED_SLASHES);
