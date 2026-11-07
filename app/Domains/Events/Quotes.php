<?php

declare(strict_types=1);

namespace App\Domains\Events;

use App\Domains\Audit\Audit;
use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventPackage;
use App\Domains\Events\Models\EventQuote;
use App\Domains\Events\Models\EventQuoteLine;
use App\Domains\Events\Models\EventSettings;
use App\Domains\Events\Models\EventSpacePrice;
use App\Domains\Finance\Taxes\TaxCalculator;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Versioned event quotes: built from the space price, packages, extras and
 * free lines, a discount (with a reason) and the Events tax; sent as a
 * private link; accepted online or by staff.
 */
final class Quotes
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TaxCalculator $taxes,
    ) {}

    /**
     * @param  list<array{kind: string, ref?: int|string|null, description?: string|null, quantity: int, unit_price?: int|null}>  $lines  unit_price in minor units, used for free lines only
     */
    public function create(Event $event, array $lines, int $discountMinor, ?string $discountReason, ?CarbonImmutable $validUntil, ?string $terms, User $by): EventQuote
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $built = [];

        foreach ($lines as $i => $line) {
            $quantity = max(1, (int) $line['quantity']);
            [$description, $unit] = match ($line['kind']) {
                'space' => $this->spaceLine($event),
                'package', 'extra' => (function () use ($line, $locale): array {
                    $item = EventPackage::query()->findOrFail((int) ($line['ref'] ?? 0));

                    return [$item->localizedName($locale, $locale), $item->price_minor];
                })(),
                default => [trim((string) ($line['description'] ?? '')), max(0, (int) ($line['unit_price'] ?? 0))],
            };

            if ($description === '') {
                throw new EventNotPossible('line', (string) __('Every free line needs a description.'));
            }

            $built[] = ['kind' => $line['kind'], 'description' => $description, 'quantity' => $quantity, 'unit_price_minor' => $unit, 'total_minor' => $unit * $quantity, 'position' => $i];
        }

        $subtotal = array_sum(array_column($built, 'total_minor'));
        $discountMinor = max(0, min($discountMinor, $subtotal));
        $net = Money::ofMinor($subtotal - $discountMinor, $hotel->currency);
        $breakdown = $this->taxes->calculate(TaxRate::query()->where('active', true)->get(), $net, TaxScope::Events, $event->date);
        $settings = EventSettings::current();

        $quote = DB::transaction(function () use ($event, $built, $subtotal, $discountMinor, $discountReason, $breakdown, $validUntil, $terms, $settings, $hotel, $by): EventQuote {
            $version = ((int) EventQuote::query()->where('event_id', $event->id)->lockForUpdate()->max('version')) + 1;
            $quote = EventQuote::query()->create([
                'event_id' => $event->id,
                'version' => $version,
                'status' => 'draft',
                'valid_until' => $validUntil ?? HotelClock::today($hotel)->addDays($settings->quote_valid_days),
                'subtotal_minor' => $subtotal,
                'discount_minor' => $discountMinor,
                'discount_reason' => $discountMinor > 0 ? $discountReason : null,
                'tax_minor' => $breakdown->totalTax()->getMinorAmount()->toInt(),
                'total_minor' => $breakdown->total->getMinorAmount()->toInt(),
                'currency' => $hotel->currency,
                'terms' => $terms ?? ($settings->terms[$hotel->default_locale] ?? null),
                'created_by' => $by->getKey(),
            ]);

            foreach ($built as $line) {
                EventQuoteLine::query()->create([...$line, 'event_quote_id' => $quote->id]);
            }

            return $quote;
        });

        Audit::record('events.quote_created', 'Event quote created', subject: $event, causer: $by, properties: ['version' => $quote->version, 'total_minor' => $quote->total_minor, 'discount_minor' => $quote->discount_minor, 'discount_reason' => $quote->discount_reason]);

        return $quote;
    }

    /**
     * Send a quote: earlier sent versions are replaced, and the client gets a
     * private link (returned once; only its hash is stored).
     */
    public function send(EventQuote $quote, User $by): string
    {
        if (! in_array($quote->status, ['draft', 'sent'], true)) {
            throw new EventNotPossible('status', (string) __('This quote can no longer be sent.'));
        }

        $token = Str::random(40);

        DB::transaction(function () use ($quote, $token): void {
            EventQuote::query()->where('event_id', $quote->event_id)->whereKeyNot($quote->id)->whereIn('status', ['draft', 'sent'])->update(['status' => 'superseded', 'token_hash' => null]);
            $quote->forceFill(['status' => 'sent', 'sent_at' => now(), 'token_hash' => hash('sha256', $token)])->save();
            Event::query()->whereKey($quote->event_id)->where('status', EventStatus::Enquiry->value)->update(['status' => EventStatus::Quoted->value]);
        });

        Audit::record('events.quote_sent', 'Event quote sent', subject: $quote->event, causer: $by, properties: ['version' => $quote->version]);

        return $token;
    }

    public static function link(string $token): string
    {
        return route('quotes.public', $token);
    }

    public function findByToken(string $token): ?EventQuote
    {
        return EventQuote::query()->with(['event', 'lines'])->where('token_hash', hash('sha256', $token))->first();
    }

    public function canBeAccepted(EventQuote $quote): bool
    {
        return $quote->status === 'sent'
            && ! $quote->valid_until->lt(HotelClock::today($this->context->hotel()))
            && $quote->event !== null && $quote->event->status->isOpen();
    }

    public function accept(EventQuote $quote, string $name, ?string $title, ?string $ip, string $via, ?User $by = null): EventQuote
    {
        if (! $this->canBeAccepted($quote)) {
            throw new EventNotPossible('status', (string) __('This quote can no longer be accepted.'));
        }

        $quote->forceFill([
            'status' => 'accepted', 'accepted_at' => now(), 'accepted_name' => mb_substr(trim($name), 0, 150),
            'accepted_title' => $title === null ? null : mb_substr(trim($title), 0, 150), 'accepted_ip' => $ip, 'accepted_via' => $via,
        ])->save();

        Jobs\TellClientAboutQuote::dispatch($quote->event_id, 'accepted');

        Audit::record('events.quote_accepted', 'Event quote accepted', subject: $quote->event, causer: $by, properties: ['version' => $quote->version, 'name' => $quote->accepted_name, 'via' => $via]);

        return $quote;
    }

    public function accepted(Event $event): ?EventQuote
    {
        return EventQuote::query()->where('event_id', $event->id)->where('status', 'accepted')->orderByDesc('version')->first();
    }

    public function depositDue(EventQuote $quote): int
    {
        return (int) ceil($quote->total_minor * EventSettings::current()->deposit_percent / 100);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function spaceLine(Event $event): array
    {
        $event->loadMissing(['space', 'block']);
        $locale = $this->context->hotel()->default_locale;

        if ($event->space === null || $event->block === null) {
            throw EventNotPossible::because('no_space');
        }

        $price = EventSpacePrice::query()->where('event_space_id', $event->space->id)->where('event_block_id', $event->block->id)->value('price_minor');

        return [$event->space->localizedName($locale, $locale).' · '.$event->block->localizedName($locale, $locale), (int) $price];
    }
}
