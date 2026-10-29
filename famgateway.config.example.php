<?php
declare(strict_types=1);

/**
 * FamGateway config template.
 * Add values later using server environment variables; do not commit real credentials.
 */
return [
    'api_key' => getenv('FAMGATEWAY_API_KEY') ?: '',
    'webhook_secret' => getenv('FAMGATEWAY_WEBHOOK_SECRET') ?: '',
    'firebase_database_url' => getenv('FIREBASE_DATABASE_URL') ?: '',
    'firebase_admin_token' => getenv('FIREBASE_ADMIN_TOKEN') ?: '',
    'firebase_service_account_json' => getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: '',
    'min_amount' => 10.00,
    'max_amount' => 50000.00,
    'currency' => 'INR',
    // Set later to your canonical HTTPS domain; never derive webhook URLs from browser headers.
    'base_url' => rtrim(getenv('PANEL_BASE_URL') ?: '', '/'),
];
