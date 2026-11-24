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

    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v25.0'),

    // Set by simulation mode: where simulated numbers ("SIM-…") are sent.
    'simulation_graph_url' => null,

    // Super admins may connect a number with a system-user token instead of
    // Embedded Signup (Meta's test number, own numbers). Off in production
    // unless explicitly enabled.
    'manual_connect' => env('WHATSAPP_MANUAL_CONNECT'),

    // Raw webhook events are kept this long for troubleshooting and replay.
    'webhook_retention_days' => 30,

];
