<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Restaurant\Menu\MenuCatalog;
use App\Domains\Restaurant\Menu\MenuItem;
use App\Domains\Restaurant\Menu\MenuLabel;
use App\Domains\Restaurant\Menu\MenuSection;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu sections and dishes, what is available today, and the QR code of
 * the public menu page.
 */
final class MenuController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request, MenuCatalog $catalog): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $outlets = Outlet::query()->orderBy('sort_order')->get();
        $outlet = $outlets->firstWhere('id', $request->query('outlet')) ?? $outlets->first();
        $rates = \App\Domains\Finance\Taxes\TaxRate::query()->where('active', true)->get()->values()->all();
        $now = CarbonImmutable::now($hotel->timezone);

        return Inertia::render('hotel/restaurant-menu', [
            'outlets' => $outlets->map(fn (Outlet $o): array => ['id' => $o->id, 'name' => $o->localizedName($locale, $locale)])->values(),
            'outlet' => $outlet === null ? null : [
                'id' => $outlet->id,
                'name' => $outlet->localizedName($locale, $locale),
                'public_url' => route('menu.public', [$hotel->slug, $outlet->public_slug]),
                'qr_url' => route('hotel.restaurant.menu.qr', $outlet),
            ],
            'sections' => $outlet === null ? [] : MenuSection::query()->with('items')->where('outlet_id', $outlet->id)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (MenuSection $s): array => [
                    'id' => $s->id,
                    'name' => (object) $s->name,
                    'items' => $s->items->map(fn (MenuItem $i): array => [
                        'id' => $i->id,
                        'name' => (object) $i->name,
                        'description' => (object) ($i->description ?? []),
                        'price' => $i->price_minor / 10 ** $this->decimals(),
                        'price_to_pay' => MoneyFormatter::format($catalog->priceToPay($i, $rates, $now), $locale),
                        'labels' => $i->labels ?? [],
                        'allergens' => $i->allergens ?? [],
                        'period_names' => implode(', ', $i->period_names ?? []),
                        'photo_url' => $i->photo_path === null ? null : route('menu.public.photo', [$hotel->slug, $outlet->public_slug, $i->id]),
                        'available_today' => $i->availableToday($hotel),
                        'active' => $i->active,
                        'orderable' => $i->orderable,
                    ])->values(),
                ])->values(),
            'labels' => array_map(fn (string $k): array => ['value' => $k, 'label' => MenuLabel::label($k)], MenuLabel::LABELS),
            'allergens' => array_map(fn (string $k): array => ['value' => $k, 'label' => MenuLabel::label($k)], MenuLabel::ALLERGENS),
            'locales' => HotelSettings::current()->enabled_locales ?? [$locale],
            'currency' => $hotel->currency,
        ]);
    }

    public function storeSection(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'string', Rule::exists('outlets', 'id')->where('hotel_id', $this->context->id())],
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:80'],
        ]);
        $section = MenuSection::query()->create([
            'outlet_id' => $data['outlet_id'],
            'name' => array_filter((array) $data['name']),
            'sort_order' => (int) MenuSection::query()->where('outlet_id', $data['outlet_id'])->max('sort_order') + 1,
        ]);
        Audit::record('menu.section_created', 'Menu section added', subject: $section, causer: $user);

        return back();
    }

    public function updateSection(Request $request, MenuSection $section): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'array'], 'name.*' => ['nullable', 'string', 'max:80'], 'sort_order' => ['nullable', 'integer', 'min:0']]);
        $section->update(['name' => array_filter((array) $data['name']), 'sort_order' => $data['sort_order'] ?? $section->sort_order]);

        return back();
    }

    public function destroySection(MenuSection $section, #[CurrentUser] User $user): RedirectResponse
    {
        foreach ($section->items as $item) {
            $this->deletePhotoFile($item);
        }
        $section->delete();
        Audit::record('menu.section_deleted', 'Menu section deleted', subject: $this->context->hotel(), causer: $user);

        return back();
    }

    public function storeItem(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $item = MenuItem::query()->create($this->itemData($request, true));
        Audit::record('menu.item_created', 'Dish added to the menu', subject: $item, causer: $user);

        return back();
    }

    public function updateItem(Request $request, MenuItem $item, #[CurrentUser] User $user): RedirectResponse
    {
        $before = $item->price_minor;
        $item->update($this->itemData($request, false));

        if ($before !== $item->price_minor) {
            Audit::record('menu.price_changed', 'Menu price changed', subject: $item, causer: $user, properties: ['old' => $before, 'new' => $item->price_minor]);
        }

        return back();
    }

    public function destroyItem(MenuItem $item, #[CurrentUser] User $user): RedirectResponse
    {
        $this->deletePhotoFile($item);
        $item->delete();
        Audit::record('menu.item_deleted', 'Dish removed from the menu', subject: $this->context->hotel(), causer: $user);

        return back();
    }

    /**
     * Mark a dish (un)available for today only; it resets the next morning.
     */
    public function today(Request $request, MenuItem $item): RedirectResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean']]);
        $item->update(['unavailable_on' => (bool) $data['available'] ? null : app(MenuCatalog::class)->today()]);

        return back();
    }

    public function photo(Request $request, MenuItem $item): RedirectResponse
    {
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:3072', 'dimensions:max_width=4000,max_height=4000']]);
        $this->deletePhotoFile($item);

        $file = $request->file('photo');
        abort_if($file === null || is_array($file), 422);
        $path = 'hotels/'.$this->context->id().'/menu/'.Str::uuid().'.'.$file->extension();
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        $item->update(['photo_path' => $path]);

        return back();
    }

    public function removePhoto(MenuItem $item): RedirectResponse
    {
        $this->deletePhotoFile($item);
        $item->update(['photo_path' => null]);

        return back();
    }

    public function qr(Outlet $outlet): HttpResponse
    {
        $url = route('menu.public', [$this->context->hotel()->slug, $outlet->public_slug]);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(480, 2), new SvgImageBackEnd)))->writeString($url);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="menu-qr-'.$outlet->public_slug.'.svg"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemData(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'menu_section_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('menu_sections', 'id')->where('hotel_id', $this->context->id())],
            'name' => ['required', 'array'],
            'name.*' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'array'],
            'description.*' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'labels' => ['array'],
            'labels.*' => [Rule::in(MenuLabel::LABELS)],
            'allergens' => ['array'],
            'allergens.*' => [Rule::in(MenuLabel::ALLERGENS)],
            'period_names' => ['nullable', 'string', 'max:200'],
            'active' => ['required', 'boolean'],
            'orderable' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $names = array_filter((array) $data['name'], fn ($v): bool => is_string($v) && trim($v) !== '');
        abort_if($names === [], 422, (string) __('Give the dish a name.'));

        return array_filter([
            'menu_section_id' => $data['menu_section_id'] ?? null,
            'name' => $names,
            'description' => array_filter((array) ($data['description'] ?? []), fn ($v): bool => is_string($v) && trim($v) !== ''),
            'price_minor' => Money::of((string) $data['price'], $this->context->hotel()->currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt(),
            'labels' => array_values(array_unique((array) ($data['labels'] ?? []))),
            'allergens' => array_values(array_unique((array) ($data['allergens'] ?? []))),
            'period_names' => array_values(array_filter(array_map('trim', explode(',', (string) ($data['period_names'] ?? ''))))),
            'active' => (bool) $data['active'],
            'orderable' => array_key_exists('orderable', $data) ? (bool) $data['orderable'] : null,
            'sort_order' => $data['sort_order'] ?? null,
        ], fn ($v): bool => $v !== null);
    }

    private function decimals(): int
    {
        return Money::zero($this->context->hotel()->currency)->getCurrency()->getDefaultFractionDigits();
    }

    private function deletePhotoFile(MenuItem $item): void
    {
        if ($item->photo_path !== null) {
            Storage::disk('local')->delete($item->photo_path);
        }
    }
}
