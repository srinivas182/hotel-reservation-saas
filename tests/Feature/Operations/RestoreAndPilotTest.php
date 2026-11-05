<?php

declare(strict_types=1);

use App\Domains\Ai\AiTask;
use App\Domains\Ai\Models\AiRun;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\Conversations\ConversationHandover;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\GoLive\Readiness;
use App\Domains\GoLive\ReadinessCheck;
use App\Domains\Operations\BackupRun;
use App\Domains\Operations\PilotMetrics;
use App\Domains\Operations\RestoreVerifier;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../WhatsApp/helpers.php';

it('verifies a restored database and records the restore drill', function (): void {
    $hotel = Hotel::factory()->create();
    User::factory()->create();
    app(TenantContext::class)->run($hotel, fn () => App\Domains\Hotel\Models\HotelSettings::current());
    DB::table('whatsapp_accounts')->insert([
        'id' => (string) Str::ulid(), 'hotel_id' => Hotel::query()->value('id'), 'waba_id' => '1', 'phone_number_id' => '2',
        'status' => 'connected', 'token' => Crypt::encryptString('secret'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $checks = app(RestoreVerifier::class)->verify(config('database.default'));
    expect(collect($checks)->every(fn (array $c): bool => $c['ok']))->toBeTrue()
        ->and(collect($checks)->firstWhere('check', 'encrypted_data')['detail'])->toBe('readable with this application key');

    // A database restored with another key is caught.
    $otherKey = new Illuminate\Encryption\Encrypter(Illuminate\Encryption\Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
    DB::table('whatsapp_accounts')->update(['token' => $otherKey->encryptString('secret')]);
    expect(collect(app(RestoreVerifier::class)->verify(config('database.default')))->firstWhere('check', 'encrypted_data')['ok'])->toBeFalse();
});

it('records a restore drill from the command', function (): void {
    Hotel::factory()->create();

    $this->artisan('backup:verify-restored', ['database' => (string) config('database.connections.'.config('database.default').'.database'), '--file' => 'x.sql.gz.enc']);

    expect(BackupRun::query()->where('kind', 'restore_drill')->sole()->details['checks'])->not->toBeEmpty();
});

it('shows the last successful backup on the go-live page', function (): void {
    $this->travelTo('2026-11-16 10:00:00');
    ['hotel' => $hotel] = assistantHotel();
    $this->artisan('backup:record', ['status' => 'success', '--file' => 'x']);

    $check = app(TenantContext::class)->run($hotel, fn (): ReadinessCheck => collect(app(Readiness::class)->checks())->firstWhere('key', 'backup_recent'));
    expect($check->ok)->toBeTrue();
});

it('measures the pilot', function (): void {
    configureMeta();
    $this->travelTo('2026-11-16 10:00:00');
    ['hotel' => $hotel, 'exe' => $exe] = assistantHotel();
    $in = fn (callable $cb) => app(TenantContext::class)->run($hotel, $cb);
    $in(fn () => App\Domains\WhatsApp\Models\WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => 'connected', 'token' => 't']));

    // Conversation A: answered in 20 seconds, never passed to staff.
    webhookPost($this, [inbound('wamid.A1', from: '2250700000001')]);
    $a = $in(fn () => Conversation::query()->sole());
    $out = fn (Conversation $c, int $seconds, string $status = 'sent', ?string $outcome = null) => $in(fn () => Message::query()->create([
        'conversation_id' => $c->id, 'direction' => 'out', 'type' => 'text', 'body' => 'x', 'status' => $status,
        'source' => MessageSource::Assistant, 'sent_at' => now()->addSeconds($seconds), 'draft_outcome' => $outcome,
    ]));
    $out($a, 20, 'sent', 'sent_unchanged');

    // Conversation B: answered after 5 minutes and passed to staff for a complaint.
    webhookPost($this, [inbound('wamid.B1', from: '2250700000002')]);
    $b = $in(fn () => Conversation::query()->whereKeyNot($a->id)->sole());
    $out($b, 300, 'sent', 'edited');
    $in(fn () => app(ConversationHandover::class)->toStaff($b, 'complaint'));

    // One request approved after 30 minutes; one still pending.
    $in(function () use ($exe): void {
        $guest = App\Domains\Bookings\Models\Guest::query()->firstOrFail();
        foreach (['approved', 'pending'] as $status) {
            BookingRequest::query()->create([
                'reference' => 'R-'.$status, 'guest_id' => $guest->id, 'status' => $status, 'room_category_id' => $exe->id,
                'check_in' => '2026-11-20', 'check_out' => '2026-11-21', 'rooms' => 1, 'adults' => 2, 'children_ages' => [],
                'price_snapshot' => [], 'total_minor' => 25000, 'currency' => 'XOF', 'expires_at' => now()->addHour(),
                'decided_at' => $status === 'approved' ? now()->addMinutes(30) : null,
            ]);
        }
        AiRun::query()->create(['task' => AiTask::Assistant, 'outcome' => 'ok', 'cost_micro_usd' => 40_000]);
        AiRun::query()->create(['task' => AiTask::Assistant, 'outcome' => 'blocked', 'cost_micro_usd' => 10_000]);
    });

    $m = $in(fn () => app(PilotMetrics::class)->measure(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-17')));

    expect($m['conversations'])->toBe(2)
        ->and($m['fast_reply_rate'])->toBe(50)
        ->and($m['without_staff_rate'])->toBe(50)
        ->and($m['requests'])->toBe(2)
        ->and($m['median_decision_minutes'])->toBe(30)
        ->and($m['request_to_booking_rate'])->toBe(50)
        ->and($m['drafts'])->toBe(['sent_unchanged' => 1, 'edited' => 1, 'discarded' => 0])
        ->and($m['handovers'])->toBe(['complaint' => 1])
        ->and($m['safety_blocks'])->toBe(1)
        ->and($m['ai_cost_usd'])->toBe(0.05)
        ->and($m['ai_cost_per_booking_usd'])->toBe(0.05);

    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    $this->actingAs($admin)->get(route('platform.pilot', ['days' => 7]))->assertOk()->assertInertia(fn ($page) => $page->has('hotels', 1));
});
