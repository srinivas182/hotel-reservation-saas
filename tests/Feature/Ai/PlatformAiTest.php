<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Agents\PingAgent;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

it('adds a provider with an encrypted key that is never sent back', function (): void {
    $this->actingAs($this->admin)->post(route('platform.ai.providers.store'), [
        'name' => 'Anthropic (MCS)', 'driver' => 'anthropic', 'key' => 'sk-ant-secret-9999', 'active' => true,
    ])->assertSessionHasNoErrors();

    expect(DB::table('ai_providers')->value('credentials'))->not->toContain('sk-ant-secret');

    $this->actingAs($this->admin)->get(route('platform.ai'))
        ->assertOk()
        ->assertDontSee('sk-ant-secret-9999')
        ->assertInertia(fn ($page) => $page->where('providers.0.key_hint', '…9999'));

    // Leaving the key empty keeps it.
    $provider = AiProvider::query()->sole();
    $this->actingAs($this->admin)->put(route('platform.ai.providers.update', $provider), [
        'name' => 'Anthropic', 'driver' => 'anthropic', 'key' => '', 'active' => true,
    ]);
    expect($provider->fresh()->credentials['key'])->toBe('sk-ant-secret-9999');
});

it('requires an address for OpenAI-compatible services', function (): void {
    $this->actingAs($this->admin)->post(route('platform.ai.providers.store'), [
        'name' => 'Local', 'driver' => 'openai-compatible', 'key' => 'x', 'active' => true,
    ])->assertSessionHasErrors('url');
});

it('records when a model is approved and stores prices in micro-dollars', function (): void {
    $provider = AiProvider::query()->create(['name' => 'A', 'driver' => 'anthropic', 'credentials' => ['key' => 'k'], 'active' => true]);

    $this->actingAs($this->admin)->post(route('platform.ai.models.store'), [
        'ai_provider_id' => $provider->id, 'model' => 'model-x', 'label' => 'Model X', 'capabilities' => ['chat', 'tools'],
        'status' => 'testing', 'input_cost' => 0.8, 'output_cost' => 4, 'audio_cost' => 0,
    ])->assertSessionHasNoErrors();

    $model = AiModel::query()->sole();
    expect($model->input_cost_per_mtok)->toBe(800_000)->and($model->certified_at)->toBeNull();

    $this->actingAs($this->admin)->put(route('platform.ai.models.update', $model), [
        'ai_provider_id' => $provider->id, 'model' => 'model-x', 'label' => 'Model X', 'capabilities' => ['chat', 'tools'],
        'status' => 'approved', 'input_cost' => 0.8, 'output_cost' => 4, 'audio_cost' => 0,
    ]);
    expect($model->fresh()->certified_at)->not->toBeNull();
});

it('sets the models per task and audits the platform off switch', function (): void {
    $models = setUpAiModels();

    $this->actingAs($this->admin)->put(route('platform.ai.settings'), [
        'assistant_model_id' => $models['main']->id, 'assistant_fallback_id' => $models['main']->id,
        'transcription_model_id' => null, 'transcription_fallback_id' => null, 'timeout_seconds' => 20, 'kill_switch' => false,
    ])->assertSessionHasErrors('assistant_fallback_id');

    $this->actingAs($this->admin)->put(route('platform.ai.settings'), [
        'assistant_model_id' => $models['backup']->id, 'assistant_fallback_id' => $models['main']->id,
        'transcription_model_id' => null, 'transcription_fallback_id' => null, 'timeout_seconds' => 30, 'kill_switch' => true,
    ])->assertSessionHasNoErrors();

    expect(AiPlatformSettings::current()->assistant_model_id)->toBe($models['backup']->id)
        ->and(AuditLog::query()->where('event', 'ai.platform_switched_off')->exists())->toBeTrue();
});

it('tests a model with a one-word prompt', function (): void {
    $models = setUpAiModels();
    PingAgent::fake(['OK']);

    $this->actingAs($this->admin)->post(route('platform.ai.models.test', $models['main']))
        ->assertSessionHas('inertia.flash_data');

    PingAgent::assertPrompted('Ping');
});

it('keeps the AI pages for super admins', function (): void {
    $support = User::factory()->withTwoFactor()->create(['platform_role' => 'support']);
    $models = setUpAiModels();

    $this->actingAs($support)->get(route('platform.ai'))->assertForbidden();
    $this->actingAs($support)->put(route('platform.ai.settings'), ['timeout_seconds' => 20, 'kill_switch' => true])->assertForbidden();
    $this->actingAs($support)->post(route('platform.ai.models.test', $models['main']))->assertForbidden();
});

it('gives assistant management to owners and managers only', function (): void {
    $hotel = Hotel::factory()->create();

    expect(SystemRole::Owner->permissions())->toContain(App\Domains\Access\Permission::AssistantManage)
        ->and(SystemRole::Manager->permissions())->toContain(App\Domains\Access\Permission::AssistantManage)
        ->and(SystemRole::Reception->permissions())->not->toContain(App\Domains\Access\Permission::AssistantManage)
        ->and(SystemRole::ReadOnly->permissions())->not->toContain(App\Domains\Access\Permission::AssistantManage);
});
