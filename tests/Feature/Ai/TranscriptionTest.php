<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\LanguageDetector;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Transcription;

require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Storage::fake('local');
    $this->travelTo('2026-11-12 10:00:00');
    $this->hotel = Hotel::factory()->create();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']));

    $openai = AiProvider::query()->create(['name' => 'OpenAI', 'driver' => 'openai', 'credentials' => ['key' => 'k1'], 'active' => true]);
    $gemini = AiProvider::query()->create(['name' => 'Gemini', 'driver' => 'gemini', 'credentials' => ['key' => 'k2'], 'active' => true]);
    $main = AiModel::query()->create(['ai_provider_id' => $openai->id, 'model' => 'transcribe-main', 'label' => 'Main', 'capabilities' => ['transcription'], 'status' => 'approved', 'audio_cost_per_minute' => 6_000]);
    $backup = AiModel::query()->create(['ai_provider_id' => $gemini->id, 'model' => 'transcribe-backup', 'label' => 'Backup', 'capabilities' => ['transcription'], 'status' => 'approved']);
    AiPlatformSettings::current()->update(['transcription_model_id' => $main->id, 'transcription_fallback_id' => $backup->id]);

    Http::fake([
        'graph.facebook.com/v23.0/media-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/media-1', 'mime_type' => 'audio/ogg', 'file_size' => 4]),
        'lookaside.fbsbx.com/*' => Http::response('OGGS', 200, ['Content-Type' => 'audio/ogg']),
    ]);
    $this->voiceNote = fn () => webhookPost($this, [inbound('wamid.V1', message: ['type' => 'audio', 'audio' => ['id' => 'media-1', 'mime_type' => 'audio/ogg', 'voice' => true]])]);
});

it('transcribes a voice note, stores it encrypted and detects the language', function (): void {
    Transcription::fake(['Bonjour, vous avez une chambre pour ce soir ?']);

    ($this->voiceNote)();

    $message = ($this->in)(fn () => Message::query()->sole());
    expect($message->transcript)->toBe('Bonjour, vous avez une chambre pour ce soir ?')
        ->and($message->transcript_language)->toBe('fr')
        ->and($message->transcribed_at)->not->toBeNull()
        ->and(DB::table('messages')->value('transcript'))->not->toContain('Bonjour')
        ->and(($this->in)(fn () => AiRun::query()->where('task', 'transcription')->where('outcome', 'ok')->count()))->toBe(1);

    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))
        ->get(route('hotel.inbox.show', $conversation))
        ->assertInertia(fn ($page) => $page->where('conversation.messages.0.transcript', 'Bonjour, vous avez une chambre pour ce soir ?'));
});

it('uses the backup transcription model when the main one fails', function (): void {
    Transcription::fake(function ($prompt): string {
        if ($prompt->model === 'transcribe-main') {
            throw new RuntimeException('Timeout');
        }

        return 'Hello, do you have a room tonight?';
    });

    ($this->voiceNote)();

    $message = ($this->in)(fn () => Message::query()->sole());
    expect($message->transcript)->toBe('Hello, do you have a room tonight?')
        ->and($message->transcript_language)->toBe('en')
        ->and(($this->in)(fn () => AiRun::query()->pluck('outcome')->all()))->toBe(['error', 'ok']);
});

it('records a transcription failure without blocking the conversation', function (): void {
    Transcription::fake();
    ($this->in)(fn () => HotelAssistantSettings::current()->update(['kill_switch' => true]));

    ($this->voiceNote)();

    $message = ($this->in)(fn () => Message::query()->sole());
    expect($message->transcript)->toBeNull()
        ->and($message->transcript_error)->toBe('AI is switched off for this hotel.')
        ->and($message->media['path'])->not->toBeNull()
        ->and(($this->in)(fn () => Conversation::query()->sole()->unread_count))->toBe(1);

    Transcription::assertNothingGenerated();
});

it('detects French and English and stays quiet when unsure', function (?string $text, ?string $expected): void {
    expect((new LanguageDetector)->detect($text))->toBe($expected);
})->with([
    ['Bonjour, combien pour une chambre ce soir ?', 'fr'],
    ['Hello, how much is a room for tonight?', 'en'],
    ['OK', null],
    ['', null],
    [null, null],
]);

it('remembers the guest\'s language from their first text message', function (): void {
    webhookPost($this, [inbound('wamid.T1', message: ['type' => 'text', 'text' => ['body' => 'Hello, do you have a room for tonight?']])]);

    $guest = ($this->in)(fn () => Conversation::query()->with('contact.guest')->sole()->contact->guest);
    expect($guest->preferred_locale)->toBe('en')
        ->and(($this->in)(fn () => Message::query()->sole()->language))->toBe('en');
});
