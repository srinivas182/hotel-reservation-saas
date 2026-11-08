<?php

declare(strict_types=1);

use App\Domains\Ai\Agents\PingAgent;
use App\Domains\Ai\AiTask;
use App\Domains\Ai\Exceptions\AiUnavailable;
use App\Domains\Ai\Gateway\AiGateway;
use App\Domains\Ai\Gateway\CircuitBreaker;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/../Ai/helpers.php';

/*
 * Meta being down is covered in tests/Feature/WhatsApp/ConversationsTest.php
 * ("retries when Meta has a server problem", "never sends the same message
 * twice"); payment providers in tests/Feature/Billing/OnlinePaymentsTest.php.
 */

beforeEach(function (): void {
    Cache::flush();
    $this->hotel = Hotel::factory()->create(['timezone' => 'Africa/Abidjan']);
    setUpAiModels();
    $this->ask = fn () => app(TenantContext::class)->run($this->hotel, fn () => app(AiGateway::class)->prompt(AiTask::Assistant, new PingAgent, 'Ping'));
});

it('stops calling an AI model that keeps failing, then tries it again a minute later', function (): void {
    $calls = [];
    PingAgent::fake(function (string $prompt, $attachments, $provider, string $model) use (&$calls): string {
        $calls[] = $model;
        if ($model === 'main-model') {
            throw new RuntimeException('Connection timed out');
        }

        return 'OK from backup';
    });

    foreach (range(1, CircuitBreaker::FAILURES) as $i) {
        ($this->ask)();
    }
    expect(array_count_values($calls)['main-model'])->toBe(CircuitBreaker::FAILURES);

    $calls = [];
    ($this->ask)();
    expect($calls)->toBe(['backup-model']); // main skipped: the guest gets an answer at once

    $this->travel(CircuitBreaker::OPEN_SECONDS + 1)->seconds();
    $calls = [];
    ($this->ask)();
    expect($calls[0])->toBe('main-model');
});

it('still tries every model when all of them are marked as failing', function (): void {
    PingAgent::fake(fn () => throw new RuntimeException('down'));
    foreach (range(1, CircuitBreaker::FAILURES) as $i) {
        try {
            ($this->ask)();
        } catch (AiUnavailable) {
        }
    }

    PingAgent::fake(['back']);
    expect(($this->ask)()->response->text)->toBe('back');
});

it('sets a timeout on every call to an outside service', function (): void {
    $missing = [];
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $code = (string) file_get_contents($file->getRealPath());
        if (str_contains($file->getRealPath(), 'SimulateGuests.php')) {
            continue; // local load-test tool, never runs in production
        }
        preg_match_all('/Http::[^;]+;/s', $code, $calls);
        foreach ($calls[0] as $call) {
            if (! str_contains($call, '->timeout(') && ! str_contains($code, 'Http::acceptJson()->asJson()->timeout(') && ! preg_match('/function client\(\)[^}]+timeout\(/s', $code)) {
                $missing[] = $file->getRelativePathname();
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([])
        ->and(config('mail.mailers.smtp.timeout'))->not->toBeNull();
});

it('gives every background job a retry policy and a time limit', function (): void {
    $missing = [];
    foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
        $code = (string) file_get_contents($file->getRealPath());
        if (! preg_match('/class \w+[^{]*implements[^{]*ShouldQueue/', $code)) {
            continue;
        }
        preg_match('/public int \$tries = (\d+);/', $code, $tries);
        if ($tries === [] || ! str_contains($code, 'public int $timeout') || ((int) $tries[1] > 1 && ! str_contains($code, '$backoff'))) {
            $missing[] = $file->getRelativePathname();
        }
    }

    expect($missing)->toBe([]);
});
