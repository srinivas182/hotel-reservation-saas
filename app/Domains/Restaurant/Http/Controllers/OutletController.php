<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\OutletClosure;
use App\Domains\Restaurant\Models\ServicePeriod;
use App\Domains\Restaurant\OutletType;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setting up restaurants, bars and their services and closed dates.
 */
final class OutletController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(): Response
    {
        $locales = HotelSettings::current()->enabled_locales ?? [$this->context->hotel()->default_locale];

        return Inertia::render('hotel/restaurant-outlets', [
            'outlets' => Outlet::query()->with(['periods', 'closures'])->orderBy('sort_order')->get()->map(fn (Outlet $o): array => [
                'id' => $o->id,
                'name' => (object) $o->name,
                'type' => $o->type->value,
                'seats' => $o->seats,
                'default_duration_minutes' => $o->default_duration_minutes,
                'slot_arrival_limit' => $o->slot_arrival_limit,
                'auto_confirm' => $o->auto_confirm,
                'auto_confirm_max_party' => $o->auto_confirm_max_party,
                'active' => $o->active,
                'orders_enabled' => $o->orders_enabled,
                'periods' => $o->periods->map(fn (ServicePeriod $p): array => [
                    'weekday' => $p->weekday, 'name' => (object) $p->name, 'starts_at' => $p->starts_at, 'ends_at' => $p->ends_at,
                    'slot_minutes' => $p->slot_minutes, 'last_seating_minutes' => $p->last_seating_minutes,
                ])->values(),
                'closures' => $o->closures->map(fn (OutletClosure $c): array => ['id' => $c->id, 'date' => $c->date->toDateString(), 'reason' => $c->reason])->values(),
            ])->values(),
            'roomCharges' => (function (): array {
                $settings = RestaurantSettings::current();
                $digits = Money::zero($this->context->hotel()->currency)->getCurrency()->getDefaultFractionDigits();

                return [
                    'room_charge_enabled' => $settings->room_charge_enabled,
                    'order_limit' => $settings->room_charge_order_limit_minor === null ? null : $settings->room_charge_order_limit_minor / 10 ** $digits,
                    'stay_limit' => $settings->room_charge_stay_limit_minor === null ? null : $settings->room_charge_stay_limit_minor / 10 ** $digits,
                    'accept_outside_orders' => $settings->accept_outside_orders,
                    'payment_methods' => $settings->methods(),
                ];
            })(),
            'allMethods' => array_map(fn (string $m): array => ['value' => $m, 'label' => RestaurantSettings::methodLabel($m)], RestaurantSettings::METHODS),
            'currency' => $this->context->hotel()->currency,
            'types' => collect(OutletType::cases())->map(fn (OutletType $t): array => ['value' => $t->value, 'label' => $t->label()])->values(),
            'locales' => $locales,
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $this->validated($request);
        $outlet = Outlet::query()->create([...$data, 'public_slug' => $this->slug($data['name'])]);
        Audit::record('outlets.created', 'Outlet created', subject: $outlet, causer: $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Outlet added. Now add its services.')]);

        return back();
    }

    public function update(Request $request, Outlet $outlet, #[CurrentUser] User $user): RedirectResponse
    {
        $outlet->update($this->validated($request));
        Audit::record('outlets.updated', 'Outlet updated', subject: $outlet, causer: $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Outlet saved.')]);

        return back();
    }

    /**
     * Replace the outlet's weekly services.
     */
    public function periods(Request $request, Outlet $outlet, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'periods' => ['present', 'array', 'max:50'],
            'periods.*.weekday' => ['required', 'integer', 'between:1,7'],
            'periods.*.name' => ['required', 'array'],
            'periods.*.name.*' => ['nullable', 'string', 'max:60'],
            'periods.*.starts_at' => ['required', 'date_format:H:i'],
            'periods.*.ends_at' => ['required', 'date_format:H:i'],
            'periods.*.slot_minutes' => ['required', 'integer', Rule::in([15, 30, 60])],
            'periods.*.last_seating_minutes' => ['required', 'integer', 'min:0', 'max:240'],
        ]);

        foreach ($data['periods'] as $i => $period) {
            if ($period['ends_at'] <= $period['starts_at']) {
                throw ValidationException::withMessages(["periods.{$i}.ends_at" => __('A service must end after it starts, and by midnight.')]);
            }
        }

        DB::transaction(function () use ($outlet, $data): void {
            ServicePeriod::query()->where('outlet_id', $outlet->id)->delete();

            foreach ($data['periods'] as $period) {
                ServicePeriod::query()->create([...$period, 'outlet_id' => $outlet->id, 'name' => array_filter((array) $period['name'])]);
            }
        });

        Audit::record('outlets.periods_updated', 'Outlet services updated', subject: $outlet, causer: $user, properties: ['count' => count($data['periods'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Services saved.')]);

        return back();
    }

    public function roomCharges(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'room_charge_enabled' => ['required', 'boolean'],
            'order_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'stay_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'accept_outside_orders' => ['required', 'boolean'],
            'payment_methods' => ['required', 'array', 'min:1'],
            'payment_methods.*' => [Rule::in(RestaurantSettings::METHODS)],
        ]);
        $currency = $this->context->hotel()->currency;
        $minor = fn ($v): ?int => $v === null || $v === '' ? null : Money::of((string) $v, $currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt();

        $settings = RestaurantSettings::current();
        $settings->update([
            'room_charge_enabled' => (bool) $data['room_charge_enabled'],
            'room_charge_order_limit_minor' => $minor($data['order_limit'] ?? null),
            'room_charge_stay_limit_minor' => $minor($data['stay_limit'] ?? null),
            'accept_outside_orders' => (bool) $data['accept_outside_orders'],
            'payment_methods' => array_values(array_unique($data['payment_methods'])),
        ]);
        Audit::record('restaurant.room_charges_updated', 'Room charge settings updated', subject: $this->context->hotel(), causer: $user, properties: $settings->only(['room_charge_enabled', 'room_charge_order_limit_minor', 'room_charge_stay_limit_minor', 'accept_outside_orders', 'payment_methods']));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Room charge settings saved.')]);

        return back();
    }

    public function close(Request $request, Outlet $outlet, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:200']]);
        OutletClosure::query()->create(['outlet_id' => $outlet->id, 'date' => $data['date'], 'reason' => $data['reason'] ?? null]);
        Audit::record('outlets.closed', 'Outlet closed on a date', subject: $outlet, causer: $user, properties: $data);

        return back();
    }

    public function reopen(OutletClosure $closure, #[CurrentUser] User $user): RedirectResponse
    {
        $closure->delete();
        Audit::record('outlets.reopened', 'Outlet closure removed', subject: $this->context->hotel(), causer: $user, properties: ['date' => $closure->date->toDateString()]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:100'],
            'type' => ['required', Rule::enum(OutletType::class)],
            'seats' => ['required', 'integer', 'min:1', 'max:2000'],
            'default_duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'slot_arrival_limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'auto_confirm' => ['required', 'boolean'],
            'auto_confirm_max_party' => ['required', 'integer', 'min:1', 'max:100'],
            'active' => ['required', 'boolean'],
            'orders_enabled' => ['sometimes', 'boolean'],
        ]);

        $data['name'] = array_filter((array) $data['name'], fn ($v): bool => is_string($v) && trim($v) !== '');

        if ($data['name'] === []) {
            throw ValidationException::withMessages(['name' => __('Give the outlet a name.')]);
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $name
     */
    private function slug(array $name): string
    {
        $base = Str::slug((string) (array_values($name)[0] ?? 'outlet')) ?: 'outlet';
        $slug = $base;
        $i = 2;

        while (Outlet::query()->where('public_slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
