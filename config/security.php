<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Breached password check
    |--------------------------------------------------------------------------
    |
    | Passwords are checked against the Have I Been Pwned range API (only a
    | hash prefix leaves the server). Disabled in the test suite, where the
    | dedicated test fakes the HTTP call.
    |
    */

    'check_breached_passwords' => (bool) env('CHECK_BREACHED_PASSWORDS', true),

    /*
    |--------------------------------------------------------------------------
    | Minimum password length
    |--------------------------------------------------------------------------
    */

    'password_min_length' => 12,

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Report-only until violation reports are clean, then set to true.
    |
    */

    'csp' => [
        'enforce' => (bool) env('CSP_ENFORCE', false),
    ],

];
