<?php
declare(strict_types=1);

/**
 * FamGateway server configuration TEMPLATE.
 *
 * Copy this file to famgateway.config.php on the PHP host and configure
 * private values there, or set the environment variables listed below.
 * Keep famgateway.config.php out of GitHub. Never put merchant secrets or
 * Firebase Admin credentials in browser JavaScript.
 */
return [
    // Private FamGateway merchant API key. Never send this to the browser.
    'api_key' => getenv('FAMGATEWAY_API_KEY') ?: '',

    // Set only after confirming the official webhook signing secret and
    // signature format with FamGateway. Do not guess or use the API key
    // unless FamGateway's documentation explicitly says to.
    'webhook_secret' => getenv('FAMGATEWAY_WEBHOOK_SECRET') ?: '',

    // Firebase Realtime Database URL for the panel project.
    'firebase_database_url' => getenv('FIREBASE_DATABASE_URL') ?: '',

    // Server-only credential accepted by the current RTDB REST helper.
    // This is NOT the Firebase web API key and NOT a service-account JSON blob.
    'firebase_admin_token' => getenv('FIREBASE_ADMIN_TOKEN') ?: '',

    // Reserved for a future Admin SDK integration; current endpoints do not
    // automatically parse or use a service-account JSON value.
    'firebase_service_account_json' => getenv('FIREBASE_SERVICE_ACCOUNT_JSON') ?: '',

    'min_amount' => 10.00,
    'max_amount' => 50000.00,
    'currency' => 'INR',

    // Canonical HTTPS panel URL. Never derive this from untrusted request headers.
    'base_url' => rtrim(getenv('PANEL_BASE_URL') ?: '', '/'),
];
