<?php

declare(strict_types=1);

use App\Domains\Simulation\SimulatedAi;
use App\Domains\Simulation\SimulateReceipts;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Webhooks\ProcessWebhookEvent;
use App\Providers\SimulationServiceProvider;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['simulation.enabled' => true, 'simulation.meta.latency_ms' => [0, 0], 'simulation.ai.latency_ms' => [0, 0]]);
    SimulationServiceProvider::routes();
    app('router')->getRoutes()->refreshNameLookups();
});

it('refuses to run in production', function (): void {
    expect(fn () => SimulationServiceProvider::guard(true))->toThrow(RuntimeException::class, 'never be enabled in production');
    SimulationServiceProvider::guard(false);
});

it('accepts sends like Meta and answers them with receipts', function (): void {
    Queue::fake();

    $this->postJson('/simulation/graph/v25.0/SIM-1/messages', ['to' => '2250700000001', 'type' => 'text'])
        ->assertOk()
        ->assertJsonPath('messaging_product', 'whatsapp')
        ->assertJsonStructure(['messages' => [['id']]]);

    Queue::assertPushed(SimulateReceipts::class, fn (SimulateReceipts $job): bool => $job->phoneNumberId === 'SIM-1' && $job->to === '2250700000001');
});

it('turns receipts into normal WhatsApp notifications', function (): void {
    Queue::fake([ProcessWebhookEvent::class]);

    app()->call([new SimulateReceipts('SIM-1', 'wamid.SIM1', '2250700000001'), 'handle']);

    $event = WebhookEvent::query()->sole();
    expect($event->phone_number_id)->toBe('SIM-1')
        ->and(array_column($event->payload['statuses'], 'status'))->toBe(['sent', 'delivered', 'read']);
    Queue::assertPushed(ProcessWebhookEvent::class);
});

it('injects Meta failures and serves media', function (): void {
    config(['simulation.meta.failure_rate' => 1.0]);
    $this->postJson('/simulation/graph/v25.0/SIM-1/messages', ['to' => '1'])->assertStatus(503);

    config(['simulation.meta.failure_rate' => 0.0]);
    $this->getJson('/simulation/graph/v25.0/sim-media-1')->assertOk()->assertJsonPath('mime_type', 'audio/ogg');
    $this->get('/simulation/media/sim-media-1')->assertOk();
});

it('gives plausible assistant answers without prices, and can fail', function (): void {
    expect(SimulatedAi::assistantReply('Je voudrais réserver')['intent'])->toBe('booking_request')
        ->and(SimulatedAi::assistantReply('Hello, what is the price?')['language'])->toBe('en')
        ->and(SimulatedAi::assistantReply('La clim, c’est inadmissible')['intent'])->toBe('complaint')
        ->and(SimulatedAi::assistantReply('Bonjour')['reply'])->not->toMatch('/\d/');

    config(['simulation.ai.failure_rate' => 1.0]);
    expect(fn () => SimulatedAi::assistantReply('Bonjour'))->toThrow(RuntimeException::class);
});

it('only prepares data or sends simulated guests in simulation mode', function (): void {
    config(['simulation.enabled' => false]);

    $this->artisan('simulation:prepare')->assertFailed();
    $this->artisan('simulation:guests', ['phone_number_id' => 'SIM-1'])->assertFailed();
});

it('gives staging a simulated AI model when none is set up, without taking over real ones', function (): void {
    $this->artisan('simulation:prepare')->assertSuccessful();

    $settings = App\Domains\Ai\Models\AiPlatformSettings::current();
    $model = App\Domains\Ai\Models\AiModel::query()->findOrFail($settings->assistant_model_id);
    expect($model->model)->toBe('simulated-model')
        ->and($model->status)->toBe(App\Domains\Ai\ModelStatus::Testing) // the launch checklist still waits for a real, approved model
        ->and($settings->modelsFor(App\Domains\Ai\AiTask::Translation))->toHaveCount(1);

    require_once __DIR__.'/../Ai/helpers.php';
    $real = setUpAiModels();
    $this->artisan('simulation:prepare')->assertSuccessful();
    expect(App\Domains\Ai\Models\AiPlatformSettings::current()->assistant_model_id)->toBe($real['main']->id);
});
