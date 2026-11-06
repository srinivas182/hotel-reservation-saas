<?php

declare(strict_types=1);

/*
 * Simulation mode (staging only): a fake Meta Graph API and a fake AI
 * provider, so load tests and failure drills never touch real guests,
 * real WhatsApp numbers or paid AI. The application refuses to start
 * in production with this switched on.
 */
return [

    'enabled' => (bool) env('SIMULATION_MODE', false),

    'meta' => [
        // Share of sends answered with a 503 (0 to 1).
        'failure_rate' => (float) env('SIMULATION_META_FAILURE_RATE', 0),
        'latency_ms' => [(int) env('SIMULATION_META_LATENCY_MIN', 80), (int) env('SIMULATION_META_LATENCY_MAX', 400)],
        // Delivery and read receipts arrive this many seconds after a send.
        'status_delay_seconds' => 2,
    ],

    'ai' => [
        'failure_rate' => (float) env('SIMULATION_AI_FAILURE_RATE', 0),
        'latency_ms' => [(int) env('SIMULATION_AI_LATENCY_MIN', 400), (int) env('SIMULATION_AI_LATENCY_MAX', 2500)],
    ],

];
