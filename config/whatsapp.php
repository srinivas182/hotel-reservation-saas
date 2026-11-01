<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Meta app (MCS as WhatsApp Tech Provider)
    |--------------------------------------------------------------------------
    |
    | One Meta app serves every hotel. Hotels connect their own WhatsApp
    | Business accounts to it through Embedded Signup (v4).
    |
    */

    'app_id' => env('WHATSAPP_APP_ID'),

    'app_secret' => env('WHATSAPP_APP_SECRET'),

    // Facebook Login for Business configuration used by Embedded Signup.
    'config_id' => env('WHATSAPP_CONFIG_ID'),

    // Shared secret Meta echoes back when verifying the webhook address.
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),

    'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),

    // Raw webhook events are kept this long for troubleshooting and replay.
    'webhook_retention_days' => 30,

];
