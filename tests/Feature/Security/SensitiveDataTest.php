<?php

declare(strict_types=1);

use App\Domains\Bookings\Models\Guest;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Support\Logging\RedactSensitiveData;
use Illuminate\Support\Facades\DB;

/**
 * Columns that must always be stored encrypted.
 */
const ENCRYPTED_COLUMNS = [
    App\Domains\WhatsApp\Models\WhatsAppAccount::class => ['token', 'pin'],
    App\Domains\WhatsApp\Models\WebhookEvent::class => ['payload'],
    App\Domains\Messaging\Email\Models\MailProvider::class => ['credentials'],
    App\Domains\Ai\Models\AiProvider::class => ['credentials'],
    App\Domains\Ai\Models\AiRun::class => ['request', 'response'],
    App\Domains\Billing\Payments\PaymentGateway::class => ['credentials'],
    App\Domains\Conversations\Models\Message::class => ['body', 'translation', 'transcript'],
    App\Domains\Conversations\Models\ConversationNote::class => ['body'],
    App\Domains\Platform\Models\HotelNote::class => ['body'],
    Guest::class => ['notes'],
    App\Domains\Bookings\Models\Booking::class => ['notes'],
    App\Domains\Events\Models\Event::class => ['notes'],
    App\Domains\Restaurant\Models\TableBooking::class => ['notes'],
    App\Domains\Orders\Models\Order::class => ['notes'],
];

it('stores secrets and personal notes encrypted', function (string $model, array $columns): void {
    $casts = (new $model)->getCasts();

    foreach ($columns as $column) {
        expect($casts[$column] ?? null)->toStartWith('encrypted', "{$model}::{$column} must be encrypted");
    }
})->with(collect(ENCRYPTED_COLUMNS)->map(fn ($c, $m) => [$m, $c])->values()->all());

it('really writes ciphertext to the database', function (): void {
    $hotel = Hotel::factory()->create();
    $guest = app(TenantContext::class)->run($hotel, fn () => Guest::query()->create(['first_name' => 'Awa', 'notes' => 'Allergique aux arachides']));

    expect((string) DB::table('guests')->where('id', $guest->id)->value('notes'))->not->toContain('arachides')
        ->and(app(TenantContext::class)->run($hotel, fn () => Guest::query()->find($guest->id)?->notes))->toBe('Allergique aux arachides');
});

it('removes phone numbers, emails and secrets from logs', function (): void {
    expect(RedactSensitiveData::redact('Guest +225 07 08 09 10 11 (awa@example.com) sent token=abc and Bearer EAAGm0PX4ZCpsBAJZBabcdefghijklmnopq'))
        ->toBe('Guest [phone] ([email]) sent token=[secret] and Bearer [secret]')
        ->and(RedactSensitiveData::redactArray(['phone' => '+2250708091011', 'api_key' => 'x', 'nested' => ['to' => 'b@c.ci']]))
        ->toBe(['phone' => '[phone]', 'api_key' => '[secret]', 'nested' => ['to' => '[email]']])
        ->and(RedactSensitiveData::redact('Booking R-26-0042 for 2 nights, 45000 FCFA'))->toBe('Booking R-26-0042 for 2 nights, 45000 FCFA');

    foreach (config('logging.channels') as $name => $channel) {
        // Stacks reuse other channels; Sentry channels are scrubbed by the error tracker's own filter.
        if (! in_array($channel['driver'] ?? 'none', ['stack', 'custom', 'none', 'sentry', 'sentry_logs'], true)) {
            expect([$name => $channel['tap'] ?? []])->toHaveKey($name)->and($channel['tap'] ?? [])->toContain(RedactSensitiveData::class);
        }
    }
});
