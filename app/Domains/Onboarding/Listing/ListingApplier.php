<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listing;

use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Audit\Audit;
use App\Domains\Billing\Payments\Drivers\Amounts;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Onboarding\Models\ListingImport;
use App\Domains\Onboarding\QuickRooms;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rooms\Models\Room;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Puts what the owner ticked on the review screen into the hotel's data.
 * Only ticked items are used; nothing already set is removed. Rooms go
 * through the quick-rooms builder, prices become the category's base
 * price, information becomes draft entries of the pack (published by the
 * owner as usual).
 */
final class ListingApplier
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly QuickRooms $quickRooms,
    ) {}

    /**
     * @param  array{profile?: list<string>, rooms?: list<array{index: int, name: string, count: int, adults: int, price: int|null}>, entries?: list<int>}  $choice
     * @return array{profile: int, categories: int, rooms: int, prices: int, entries: int}
     */
    public function apply(ListingImport $import, array $choice, User $by): array
    {
        if ($import->status !== 'ready' || ! is_array($import->draft)) {
            throw new RuntimeException((string) __('This import cannot be used any more. Start a new one.'));
        }

        $draft = $import->draft;
        $hotel = $this->context->hotel();
        $canRooms = $by->can('rooms.manage');
        $canRates = $by->can('rates.manage');
        $canPack = $by->can('assistant.manage');

        $result = DB::transaction(function () use ($import, $draft, $choice, $hotel, $by, $canRooms, $canRates, $canPack): array {
            $counts = ['profile' => 0, 'categories' => 0, 'rooms' => 0, 'prices' => 0, 'entries' => 0];

            // 1. Hotel details.
            $settings = HotelSettings::current();
            foreach (array_unique($choice['profile'] ?? []) as $key) {
                $value = $draft['profile'][$key]['value'] ?? null;
                if ($value === null) {
                    continue;
                }

                match ($key) {
                    'name' => $hotel->forceFill(['name' => (string) $value])->save(),
                    'location' => $settings->fill([
                        'latitude' => (float) $value['latitude'],
                        'longitude' => (float) $value['longitude'],
                        'map_url' => $settings->map_url ?: ($import->maps_url !== null && ! MapsLink::isShortLink($import->maps_url) ? mb_substr($import->maps_url, 0, 500) : MapsLink::pinUrl((float) $value['latitude'], (float) $value['longitude'])),
                    ]),
                    'address_line1', 'city', 'check_in_time', 'check_out_time' => $settings->fill([$key => (string) $value]),
                    default => null,
                };
                $counts['profile']++;
            }
            $settings->save();

            // 2. Room categories, rooms and base prices.
            $rows = [];
            foreach ($canRooms ? ($choice['rooms'] ?? []) : [] as $room) {
                if (! isset($draft['rooms'][$room['index']])) {
                    continue;
                }
                $rows[] = $room;
            }

            if ($rows !== []) {
                $numbers = Room::query()->pluck('number')->filter(fn ($n): bool => ctype_digit((string) $n))->map(fn ($n): int => (int) $n);
                $created = $this->quickRooms->create(
                    array_map(fn (array $r): array => ['name' => $r['name'], 'count' => $r['count'], 'adults' => $r['adults']], $rows),
                    $numbers->isEmpty() ? 101 : $numbers->max() + 1,
                    $by,
                );
                $counts['categories'] = $created['categories'];
                $counts['rooms'] = $created['rooms'];

                foreach ($rows as $i => $row) {
                    $categoryId = $created['ids'][$i] ?? null;
                    if (! $canRates || $categoryId === null || $row['price'] === null || CategoryRate::query()->where('room_category_id', $categoryId)->exists()) {
                        continue;
                    }

                    CategoryRate::query()->create(['room_category_id' => $categoryId, 'base_minor' => Amounts::fromMajor($row['price'], $hotel->currency)]);
                    $counts['prices']++;
                }
            }

            // 3. Information pack entries, added after what is already there.
            $sort = (int) KnowledgeEntry::query()->max('sort_order');
            foreach ($canPack ? array_unique($choice['entries'] ?? []) : [] as $index) {
                $entry = $draft['entries'][$index] ?? null;
                if (! is_array($entry)) {
                    continue;
                }

                KnowledgeEntry::query()->create(['locale' => $entry['locale'], 'section' => $entry['section'], 'question' => $entry['question'], 'content' => $entry['content'], 'sort_order' => ++$sort]);
                $counts['entries']++;
            }

            $import->forceFill(['status' => 'applied', 'applied' => $choice, 'applied_at' => now()])->save();

            return $counts;
        });

        $import->deleteFiles();
        Audit::record('onboarding.listing_applied', 'Set-up import used', subject: $hotel, causer: $by, properties: ['import' => $import->id, ...$result]);

        return $result;
    }
}
