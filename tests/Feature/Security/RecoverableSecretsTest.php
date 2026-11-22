<?php

declare(strict_types=1);

use App\Domains\Ai\AiDriver;
use App\Domains\Ai\Models\AiProvider;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

it('opens Platform → AI when a provider key was saved under another APP_KEY, and asks for the key again', function (): void {
    $provider = AiProvider::query()->create(['name' => 'Old', 'driver' => AiDriver::Anthropic, 'credentials' => ['key' => 'sk-current-1234'], 'active' => true]);
    $other = new Encrypter(random_bytes(32), 'aes-256-cbc');
    DB::table('ai_providers')->where('id', $provider->id)->update(['credentials' => $other->encryptString(json_encode(['key' => 'sk-old']))]);

    expect($provider->refresh()->credentials)->toBe([]);

    $this->actingAs(User::factory()->platformAdmin()->withTwoFactor()->create())
        ->get(route('platform.ai'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('providers.0.key_hint', null));
});

it('still encrypts and reads keys normally', function (): void {
    $provider = AiProvider::query()->create(['name' => 'New', 'driver' => AiDriver::OpenAi, 'credentials' => ['key' => 'sk-abcd'], 'active' => true]);

    expect(DB::table('ai_providers')->where('id', $provider->id)->value('credentials'))->not->toContain('sk-abcd')
        ->and($provider->refresh()->credentials)->toBe(['key' => 'sk-abcd']);
});
