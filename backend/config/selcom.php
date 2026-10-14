<?php

// Private, operator-provisioned credentials. Never fall back to another business's merchant.
return [
    'business_public_id' => env('SELCOM_BUSINESS_PUBLIC_ID'),
    'base_url' => env('SELCOM_BASE_URL'),
    'api_key' => env('SELCOM_API_KEY'),
    'api_secret' => env('SELCOM_API_SECRET'),
    'vendor' => env('SELCOM_VENDOR'),
    'webhook_url' => env('SELCOM_WEBHOOK_URL'),
];
