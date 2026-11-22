<?php

declare(strict_types=1);

use App\Domains\Ai\AiDriver;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Identity\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

it('loads OpenAI chat and speech models from the key, and skips the rest', function (): void {
    $provider = AiProvider::query()->create(['name' => 'OpenAI', 'driver' => AiDriver::OpenAi, 'credentials' => ['key' => 'sk-proj-abc'], 'active' => true]);
    AiModel::query()->create(['ai_provider_id' => $provider->id, 'model' => 'gpt-4.1-mini', 'label' => 'Mini', 'capabilities' => ['chat'], 'status' => 'testing', 'input_cost_per_mtok' => 0, 'output_cost_per_mtok' => 0]);
    Http::fake(['api.openai.com/v1/models' => Http::response(['data' => [
        ['id' => 'gpt-4.1-mini'], ['id' => 'gpt-4o-transcribe'], ['id' => 'whisper-1'], ['id' => 'o4-mini'],
        ['id' => 'text-embedding-3-small'], ['id' => 'tts-1'], ['id' => 'dall-e-3'], ['id' => 'omni-moderation-latest'],
    ]])]);

    $this->actingAs($this->admin)->getJson(route('platform.ai.providers.models', $provider))
        ->assertOk()
        ->assertExactJson(['models' => [
            ['id' => 'gpt-4.1-mini', 'label' => 'gpt-4.1-mini', 'capabilities' => ['chat', 'tools'], 'added' => true],
            ['id' => 'gpt-4o-transcribe', 'label' => 'gpt-4o-transcribe', 'capabilities' => ['transcription'], 'added' => false],
            ['id' => 'o4-mini', 'label' => 'o4-mini', 'capabilities' => ['chat', 'tools'], 'added' => false],
            ['id' => 'whisper-1', 'label' => 'whisper-1', 'capabilities' => ['transcription'], 'added' => false],
        ]]);

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer sk-proj-abc'));
});

it('loads Anthropic and Gemini models with their display names', function (): void {
    $claude = AiProvider::query()->create(['name' => 'Anthropic', 'driver' => AiDriver::Anthropic, 'credentials' => ['key' => 'sk-ant-x'], 'active' => true]);
    $gemini = AiProvider::query()->create(['name' => 'Gemini', 'driver' => AiDriver::Gemini, 'credentials' => ['key' => 'AIza-x'], 'active' => true]);
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response(['data' => [['id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5']]]),
        'generativelanguage.googleapis.com/*' => Http::response(['models' => [
            ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/text-embedding-004', 'displayName' => 'Embedding', 'supportedGenerationMethods' => ['embedContent']],
        ]]),
    ]);

    $this->actingAs($this->admin)->getJson(route('platform.ai.providers.models', $claude))
        ->assertJsonPath('models.0.id', 'claude-haiku-5-5')->assertJsonPath('models.0.label', 'Claude Haiku 5.5');
    $this->actingAs($this->admin)->getJson(route('platform.ai.providers.models', $gemini))
        ->assertJsonCount(1, 'models')->assertJsonPath('models.0.id', 'gemini-2.5-flash');

    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'anthropic') && $r->hasHeader('x-api-key', 'sk-ant-x'));
});

it('explains a refused key, and is for super admins only', function (): void {
    $provider = AiProvider::query()->create(['name' => 'OpenAI', 'driver' => AiDriver::OpenAi, 'credentials' => ['key' => 'bad'], 'active' => true]);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key provided']], 401)]);

    $this->actingAs($this->admin)->getJson(route('platform.ai.providers.models', $provider))
        ->assertStatus(422)->assertJsonPath('message', 'The provider refused: Incorrect API key provided');

    $ops = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();
    $this->actingAs($ops)->getJson(route('platform.ai.providers.models', $provider))->assertForbidden();
});
