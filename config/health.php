<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Health check token
    |--------------------------------------------------------------------------
    |
    | Monitoring calls /health with this token in the X-Health-Token header
    | (or ?token=). When empty, /health is disabled and returns 404.
    |
    */

    'token' => env('HEALTH_CHECK_TOKEN'),

];
