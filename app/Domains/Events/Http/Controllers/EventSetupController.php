<?php

declare(strict_types=1);

namespace App\Domains\Events\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Events\Layout;
use App\Domains\Events\Models\EventBlock;
use App\Domains\Events\Models\EventPackage;
use App\Domains\Events\Models\EventSpace;
use App\Domains\Events\Models\EventSpacePrice;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Event spaces, time blocks, prices, packages and extras.
 */
final class EventSetupController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        $locales = HotelSettings::current()->enabled_locales ?? [$this->context->hotel()->default_locale];
        $digits = $this->digits();

        return Inertia::render('hotel/events-setup', [
            'spaces' => EventSpace::query()->orderBy('sort_order')->get()->map(fn (EventSpace $s): array => [
                'id' => $s->id, 'name' => (object) $s->name, 'capacities' => (object) $s->capacities, 'combines' => $s->combines ?? [], 'active' => $s->active,
            ])->values(),
            'blocks' => EventBlock::query()->orderBy('sort_order')->orderBy('starts_at')->get()->map(fn (EventBlock $b): array => [
                'id' => $b->id, 'name' => (object) $b->name, 'starts_at' => $b->starts_at, 'ends_at' => $b->ends_at, 'covers' => $b->covers ?? [],
            ])->values(),
            'prices' => EventSpacePrice::query()->get()->map(fn (EventSpacePrice $p): array => [
                'space' => $p->event_space_id, 'block' => $p->event_block_id, 'price' => $p->price_minor / 10 ** $digits,
            ])->values(),
            'packages' => EventPackage::query()->orderBy('kind')->orderBy('sort_order')->get()->map(fn (EventPackage $p): array => [
                'id' => $p->id, 'kind' => $p->kind, 'name' => (object) $p->name, 'price' => $p->price_minor / 10 ** $digits, 'unit' => $p->unit, 'active' => $p->active,
            ])->values(),
            'layouts' => collect(Layout::cases())->map(fn (Layout $l): array => ['value' => $l->value, 'label' => $l->label()])->values(),
            'locales' => $locales,
            'settings' => (function (): array {
                $s = \App\Domains\Events\Models\EventSettings::current();

                return ['deposit_percent' => $s->deposit_percent, 'option_days' => $s->option_days, 'quote_valid_days' => $s->quote_valid_days, 'terms' => (object) ($s->terms ?? [])];
            })(),
            'currency' => $this->context->hotel()->currency,
        ]);
    }

    public function storeSpace(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveSpace($request, $user, null);
    }

    public function updateSpace(Request $request, EventSpace $space, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveSpace($request, $user, $space);
    }

    private function saveSpace(Request $request, User $user, ?EventSpace $space): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:100'],
            'capacities' => ['required', 'array'],
            'capacities.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'combines' => ['array'],
            'combines.*' => ['string', Rule::exists('event_spaces', 'id')->where('hotel_id', $this->context->id())],
            'active' => ['required', 'boolean'],
        ]);
        $name = array_filter((array) $data['name'], fn ($v): bool => is_string($v) && trim($v) !== '');
        $capacities = array_filter(array_map('intval', array_intersect_key((array) $data['capacities'], array_flip(array_map(fn (Layout $l): string => $l->value, Layout::cases())))));

        if ($name === [] || $capacities === []) {
            throw ValidationException::withMessages(['name' => __('Give the space a name and at least one capacity.')]);
        }

        $values = ['name' => $name, 'capacities' => $capacities, 'combines' => array_values(array_diff((array) ($data['combines'] ?? []), [$space?->id])), 'active' => (bool) $data['active']];
        $space === null ? $space = EventSpace::query()->create($values) : $space->update($values);
        Audit::record('events.space_saved', 'Event space saved', subject: $space, causer: $user);

        return back();
    }

    public function storeBlock(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveBlock($request, $user, null);
    }

    public function updateBlock(Request $request, EventBlock $block, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveBlock($request, $user, $block);
    }

    private function saveBlock(Request $request, User $user, ?EventBlock $block): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:60'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'covers' => ['array'],
            'covers.*' => ['integer', Rule::exists('event_blocks', 'id')->where('hotel_id', $this->context->id())],
        ]);
        $values = [
            'name' => array_filter((array) $data['name']),
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'covers' => array_values(array_diff(array_map('intval', (array) ($data['covers'] ?? [])), [$block?->id])),
        ];
        $block === null ? EventBlock::query()->create($values) : $block->update($values);
        Audit::record('events.block_saved', 'Event time block saved', subject: $this->context->hotel(), causer: $user);

        return back();
    }

    public function savePrices(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'prices' => ['present', 'array'],
            'prices.*.space' => ['required', 'string', Rule::exists('event_spaces', 'id')->where('hotel_id', $this->context->id())],
            'prices.*.block' => ['required', 'integer', Rule::exists('event_blocks', 'id')->where('hotel_id', $this->context->id())],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        foreach ($data['prices'] as $row) {
            if ($row['price'] === null || $row['price'] === '') {
                EventSpacePrice::query()->where('event_space_id', $row['space'])->where('event_block_id', $row['block'])->delete();

                continue;
            }

            EventSpacePrice::query()->updateOrCreate(
                ['event_space_id' => $row['space'], 'event_block_id' => (int) $row['block']],
                ['price_minor' => $this->minor((string) $row['price'])],
            );
        }

        Audit::record('events.prices_saved', 'Event space prices saved', subject: $this->context->hotel(), causer: $user);

        return back();
    }

    public function storePackage(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->savePackage($request, $user, null);
    }

    public function updatePackage(Request $request, EventPackage $package, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->savePackage($request, $user, $package);
    }

    private function savePackage(Request $request, User $user, ?EventPackage $package): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['package', 'extra'])],
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'unit' => ['required', Rule::in(EventPackage::UNITS)],
            'active' => ['required', 'boolean'],
        ]);
        $values = ['kind' => $data['kind'], 'name' => array_filter((array) $data['name']), 'price_minor' => $this->minor((string) $data['price']), 'unit' => $data['unit'], 'active' => (bool) $data['active']];
        $package === null ? EventPackage::query()->create($values) : $package->update($values);
        Audit::record('events.package_saved', 'Event package or extra saved', subject: $this->context->hotel(), causer: $user);

        return back();
    }

    private function minor(string $amount): int
    {
        return Money::of($amount, $this->context->hotel()->currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt();
    }

    private function digits(): int
    {
        return Money::zero($this->context->hotel()->currency)->getCurrency()->getDefaultFractionDigits();
    }
}
