<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Agents\PingAgent;
use App\Domains\Ai\AiTask;
use App\Domains\Ai\Exceptions\AiUnavailable;
use App\Domains\Ai\Gateway\AiGateway;
use App\Domains\Ai\Mail\AiBudgetAlertMail;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-12 10:00:00');
    $this->hotel = Hotel::factory()->create(['timezone' => 'Africa/Abidjan']);
    $this->models = setUpAiModels();
    $this->context = app(TenantContext::class);
    $this->in = fn (callable $callback) => $this->context->run($this->hotel, $callback);
    $this->ask = fn () => ($this->in)(fn () => app(AiGateway::class)->prompt(AiTask::Assistant, new PingAgent, 'Ping'));
});

it('answers with the main model and records the call', function (): void {
    PingAgent::fake(['OK']);

    $result = ($this->ask)();

    expect($result->model->model)->toBe('main-model')
        ->and($result->response->text)->toBe('OK')
        ->and($result->run->outcome)->toBe('ok')
        ->and(($this->in)(fn () => AiRun::query()->count()))->toBe(1)
        ->and(($this->in)(fn () => UsageRecord::query()->where('meter', 'ai.assistant')->sum('quantity')))->toEqual(1);
});

it('moves to the backup model when the main one fails, recording both', function (): void {
    PingAgent::fake(function (string $prompt, $attachments, $provider, string $model): string {
        if ($model === 'main-model') {
            throw new RuntimeException('Connection timed out');
        }

        return 'OK from backup';
    });

    $result = ($this->ask)();

    expect($result->model->model)->toBe('backup-model')
        ->and($result->failedModels)->toBe(['main-model'])
        ->and(($this->in)(fn () => AiRun::query()->orderBy('id')->pluck('outcome')->all()))->toBe(['error', 'ok']);
});

it('reports that AI is unavailable when every model fails', function (): void {
    PingAgent::fake(fn () => throw new RuntimeException('Service unavailable'));

    expect($this->ask)->toThrow(fn (AiUnavailable $e) => expect($e->reason)->toBe('all_failed'));
    expect(($this->in)(fn () => AiRun::query()->where('outcome', 'error')->count()))->toBe(2);
});

it('stops all AI calls when switched off for the platform or the hotel', function (): void {
    PingAgent::fake(['OK']);

    AiPlatformSettings::current()->update(['kill_switch' => true]);
    expect($this->ask)->toThrow(fn (AiUnavailable $e) => expect($e->reason)->toBe('platform_off'));

    AiPlatformSettings::current()->update(['kill_switch' => false]);
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['kill_switch' => true]));
    expect($this->ask)->toThrow(fn (AiUnavailable $e) => expect($e->reason)->toBe('hotel_off'));

    PingAgent::assertNeverPrompted();
});

it('stops at the daily spending limit, counting only today', function (): void {
    PingAgent::fake(['OK', 'OK']);
    ($this->in)(function (): void {
        HotelAssistantSettings::current()->update(['daily_budget_micro_usd' => 1_000_000]);
        AiRun::query()->create(['task' => AiTask::Assistant, 'cost_micro_usd' => 900_000, 'outcome' => 'ok']);
    });
    DB::table('ai_runs')->update(['created_at' => '2026-11-11 12:00:00']);

    ($this->ask)();

    ($this->in)(fn () => AiRun::query()->create(['task' => AiTask::Assistant, 'cost_micro_usd' => 1_000_000, 'outcome' => 'ok']));
    expect($this->ask)->toThrow(fn (AiUnavailable $e) => expect($e->reason)->toBe('budget'));
});

it('warns the assistant managers once a day at 80% of the limit', function (): void {
    Mail::fake();
    PingAgent::fake(['OK', 'OK']);
    $owner = staffMember($this->hotel, SystemRole::Owner);
    staffMember($this->hotel, SystemRole::Reception);
    ($this->in)(function (): void {
        HotelAssistantSettings::current()->update(['daily_budget_micro_usd' => 1_000_000]);
        AiRun::query()->create(['task' => AiTask::Assistant, 'cost_micro_usd' => 850_000, 'outcome' => 'ok']);
    });

    ($this->ask)();
    ($this->ask)();

    Mail::assertQueued(AiBudgetAlertMail::class, 1);
    Mail::assertQueued(AiBudgetAlertMail::class, fn (AiBudgetAlertMail $mail): bool => $mail->hasTo($owner->email));
});

it('says when no model is set up', function (): void {
    AiPlatformSettings::current()->update(['assistant_model_id' => null, 'assistant_fallback_id' => null]);

    expect($this->ask)->toThrow(fn (AiUnavailable $e) => expect($e->reason)->toBe('no_model'));
});

it('skips retired models and inactive providers', function (): void {
    PingAgent::fake(['OK']);
    $this->models['main']->update(['status' => 'retired']);

    expect(($this->ask)()->model->model)->toBe('backup-model');
});

it('encrypts the stored request and reply and deletes records after 30 days', function (): void {
    PingAgent::fake(['Secret reply']);
    ($this->ask)();

    expect(DB::table('ai_runs')->value('response'))->not->toContain('Secret')
        ->and(($this->in)(fn () => AiRun::query()->sole()->response))->toBe('Secret reply');

    $this->travel(31)->days();
    $this->artisan('model:prune', ['--model' => [AiRun::class]])->assertSuccessful();
    expect(DB::table('ai_runs')->count())->toBe(0);
});

it('calculates call cost in micro-dollars', function (): void {
    $model = new AiModel(['input_cost_per_mtok' => 3_000_000, 'output_cost_per_mtok' => 15_000_000, 'audio_cost_per_minute' => 6_000]);

    expect($model->cost(2_000, 500))->toBe(13_500)
        ->and($model->cost(0, 0, 30))->toBe(3_000)
        ->and($model->cost(1, 0))->toBe(3);
});

it('registers provider keys with laravel/ai at run time', function (): void {
    $provider = $this->models['main']->provider;
    $name = app(App\Domains\Ai\Gateway\ProviderRegistry::class)->register($provider);

    expect(config("ai.providers.{$name}"))->toBe(['driver' => 'anthropic', 'key' => 'sk-ant-test-1234']);
});
