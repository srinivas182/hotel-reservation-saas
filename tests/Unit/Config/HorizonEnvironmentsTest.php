<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/**
 * Horizon starts workers only for an APP_ENV listed in horizon.environments
 * (matched like Str::is, first match wins). An unlisted APP_ENV leaves
 * Horizon "running" with no worker, and every job waits forever.
 */
it('starts workers for every APP_ENV a server may use', function (string $env): void {
    $environments = require __DIR__.'/../../../config/horizon.php';

    $match = collect($environments['environments'])->first(fn (array $_, string $name): bool => Str::is($name, $env));

    expect($match)->not->toBeNull()
        ->and($match['supervisor-1']['maxProcesses'] ?? 0)->toBeGreaterThan(0);
})->with(['production', 'staging', 'local', 'demo', 'preprod']);

it('keeps production on its own, larger pool', function (): void {
    $environments = (require __DIR__.'/../../../config/horizon.php')['environments'];

    expect(array_key_first($environments))->toBe('production')
        ->and(array_key_last($environments))->toBe('*')
        ->and($environments['production']['supervisor-1']['maxProcesses'])->toBe(10);
});
