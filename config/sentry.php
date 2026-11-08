<?php

declare(strict_types=1);

/*
| Error tracking (S16). Off unless SENTRY_LARAVEL_DSN is set; works with
| Sentry's hosted service or a self-hosted GlitchTip. Personal data is
| removed before sending (App\Support\Logging\SentryScrubber).
*/
return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'release' => is_file(base_path('VERSION')) ? trim((string) file_get_contents(base_path('VERSION'))) : null,
    'environment' => env('APP_ENV', 'production'),
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),
    'send_default_pii' => false,
    'before_send' => [App\Support\Logging\SentryScrubber::class, 'beforeSend'],
    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
    ],
    'tracing' => [
        'sql_bindings' => false,
    ],
];
