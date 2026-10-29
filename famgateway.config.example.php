<?php
declare(strict_types=1);

/**
 * FamGateway config.
 * Set FAMGATEWAY_API_KEY and PANEL_ADMIN_SECRET as server environment variables.
 * Never put the merchant key into browser JavaScript or commit real keys.
 */
return [
    'api_key' => getenv('FAMGATEWAY_API_KEY') ?: '',
    'webhook_secret' => getenv('FAMGATEWAY_WEBHOOK_SECRET') ?: (getenv('FAMGATEWAY_API_KEY') ?: ''),
    'firebase_database_url' => getenv('FIREBASE_DATABASE_URL') ?: 'https://smm-panel-86035-default-rtdb.europe-west1.firebasedatabase.app',
    'firebase_admin_token' => getenv('FIREBASE_ADMIN_TOKEN') ?: '',
    'firebase_service_account_json' => getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: '',
    'min_amount' => 10.00,
    'max_amount' => 50000.00,
    'currency' => 'INR',
];
