<?php

// The Shopify app's embedded page inside Shopify admin (resources/views/shopify/app.blade.php).
return [
    'title' => 'Le Voile CRM',
    'connecting' => 'Connecting the store to the CRM…',
    'connected' => 'The store is connected to the CRM',
    'not_connected' => 'The store is not connected',
    'shop' => 'Store',
    'status' => 'Status',
    'mode' => 'Connected through',
    'mode_app' => 'Shopify app',
    'mode_custom_token' => 'Manual connection',
    'token_valid_until' => 'Token valid until',
    'token_note' => 'Renews itself every hour',
    'last_webhook' => 'Last update received from Shopify',
    'never' => 'Nothing yet',
    'open_crm' => 'Open the CRM',
    'open_crm_note' => 'The CRM opens in a new tab, not inside Shopify.',
    'errors' => [
        'invalid_token' => 'Shopify did not send a valid authorization. Close this page and open the app again from Shopify admin.',
        'shop_not_allowed' => 'This app is not for this store.',
        'other_store' => 'The CRM is connected to another store. It cannot connect two stores.',
        'exchange_failed' => 'Shopify did not answer the token request. Try again in a minute.',
        'generic' => 'Something went wrong. Try again in a minute.',
    ],
];
