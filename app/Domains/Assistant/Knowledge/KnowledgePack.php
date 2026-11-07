<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Knowledge;

use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Hotel\Support\Locales;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The facts the assistant may use about the current hotel: facts the
 * platform already holds (generated, always current) plus the published
 * information pack. Prices are never included: they come from the quote
 * engine through the assistant's tools.
 */
final class KnowledgePack
{
    public function __construct(private readonly TenantContext $context) {}

    public function published(): ?KnowledgeVersion
    {
        return KnowledgeVersion::query()->orderByDesc('version')->first();
    }

    public function publish(?User $by = null): KnowledgeVersion
    {
        return DB::transaction(function () use ($by): KnowledgeVersion {
            $snapshot = [];

            foreach (KnowledgeEntry::query()->orderBy('section')->orderBy('sort_order')->orderBy('id')->get() as $entry) {
                $snapshot[$entry->locale][] = [
                    'section' => $entry->section->value,
                    'question' => $entry->question,
                    'content' => $entry->content,
                ];
            }

            $version = KnowledgeVersion::query()->create([
                'version' => ((int) KnowledgeVersion::query()->lockForUpdate()->max('version')) + 1,
                'snapshot' => $snapshot,
                'published_by' => $by?->getKey(),
                'published_at' => now(),
            ]);

            Audit::record('assistant.knowledge_published', 'Information pack published', subject: $this->context->hotel(), causer: $by, properties: [
                'version' => $version->version,
                'entries' => array_sum(array_map('count', $snapshot)),
            ]);

            return $version;
        });
    }

    /**
     * The text given to the assistant, in the guest's language when the pack
     * has it (otherwise the hotel's default language).
     */
    public function compile(string $locale, ?KnowledgeVersion $version = null): string
    {
        $hotel = $this->context->hotel();
        $version ??= $this->published();
        $snapshot = $version === null ? [] : $version->snapshot;
        $entries = $snapshot[$locale] ?? $snapshot[$hotel->default_locale] ?? [];

        $lines = ['## Facts from the hotel system', ...$this->facts($locale), ''];

        foreach (KnowledgeSection::cases() as $section) {
            $items = array_values(array_filter($entries, fn (array $e): bool => $e['section'] === $section->value));

            if ($items === []) {
                continue;
            }

            $lines[] = '## '.$section->label();

            foreach ($items as $item) {
                $lines[] = $item['question'] !== null && $item['question'] !== ''
                    ? 'Q: '.$item['question']."\nA: ".$item['content']
                    : $item['content'];
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return list<string>
     */
    private function facts(string $locale): array
    {
        $hotel = $this->context->hotel();
        $settings = HotelSettings::current();
        $rates = CategoryRate::query()->get()->keyBy('room_category_id');

        $facts = [
            "Hotel: {$hotel->name}",
            'Address: '.trim(implode(', ', array_filter([$settings->address_line1, $settings->address_line2, $settings->city]))),
            'Phone: '.($settings->phone_e164 ?? '—'),
            'Email: '.($settings->email ?? '—'),
            'Check-in from: '.substr((string) $settings->check_in_time, 0, 5),
            'Check-out until: '.substr((string) $settings->check_out_time, 0, 5),
            'Late check-out possible until: '.($settings->late_checkout_until === null ? 'not offered' : substr($settings->late_checkout_until, 0, 5)),
            'Currency: '.$hotel->currency,
            'Languages spoken: '.implode(', ', array_map(fn (string $code): string => Locales::SUPPORTED[$code] ?? $code, (array) ($settings->enabled_locales ?? [$hotel->default_locale]))),
            'Room categories:',
        ];

        foreach (RoomCategory::query()->where('active', true)->orderBy('sort_order')->orderBy('code')->get() as $category) {
            /** @var CategoryRate|null $rate */
            $rate = $rates->get($category->id);
            $breakfast = match ($rate?->breakfast_mode) {
                BreakfastMode::Included => 'breakfast included',
                BreakfastMode::Optional => 'breakfast optional (extra charge, use get_quote)',
                default => 'no breakfast',
            };

            $facts[] = sprintf(
                '- %s (code %s): up to %d guests (%d adults, %d children), %s%s; amenities: %s. %s',
                $category->localizedName($locale, $hotel->default_locale),
                $category->code,
                $category->max_occupancy,
                $category->max_adults,
                $category->max_children,
                $breakfast,
                $category->extra_bed_allowed ? ', extra bed possible' : '',
                implode(', ', (array) ($category->amenities ?? [])) ?: '—',
                (string) $category->localizedDescription($locale, $hotel->default_locale),
            );
        }

        $outlets = \App\Domains\Restaurant\Models\Outlet::query()->with('periods')->where('active', true)->orderBy('sort_order')->get();

        if ($outlets->isNotEmpty() && app(\App\Domains\Billing\Entitlements::class)->has(\App\Domains\Billing\Module::Restaurant)) {
            $facts[] = 'Restaurants and bars (book with restaurant_availability and book_table; menu with get_menu):';
            $days = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

            foreach ($outlets as $outlet) {
                $hours = $outlet->periods->groupBy(fn ($p): string => $p->localizedName($locale, $hotel->default_locale).' '.$p->starts_at.'–'.$p->ends_at)
                    ->map(fn ($group, string $label): string => $label.' ('.$group->map(fn ($p): string => $days[$p->weekday])->implode(', ').')')
                    ->implode('; ');
                $facts[] = sprintf('- %s (code %s, %s, %d seats'.($outlet->orders_enabled ? ', takes food and drink orders' : '').'): %s. Full menu: %s', $outlet->localizedName($locale, $hotel->default_locale), $outlet->public_slug, $outlet->type->value, $outlet->seats, $hours ?: 'no services set', route('menu.public', [$hotel->slug, $outlet->public_slug]));
            }
        }

        $spaces = \App\Domains\Events\Models\EventSpace::query()->where('active', true)->orderBy('sort_order')->get();

        if ($spaces->isNotEmpty() && app(\App\Domains\Billing\Entitlements::class)->has(\App\Domains\Billing\Module::Events)) {
            $facts[] = 'Event spaces (events team quotes them; no prices to guests; use event_enquiry):';
            foreach ($spaces as $space) {
                $capacities = collect($space->capacities)->map(fn (int $n, string $layout): string => \App\Domains\Events\Layout::from($layout)->label().' '.$n)->implode(', ');
                $facts[] = sprintf('- %s: up to %s', $space->localizedName($locale, $hotel->default_locale), $capacities);
            }
        }

        return $facts;
    }
}
