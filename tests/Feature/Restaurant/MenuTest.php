<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Restaurant\Menu\MenuCatalog;
use App\Domains\Restaurant\Menu\MenuItem;
use App\Domains\Restaurant\Menu\MenuSection;
use App\Domains\Restaurant\Models\ServicePeriod;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    Storage::fake('local');
    $this->travelTo('2026-11-13 20:00:00'); // Friday, during dinner
    $this->hotel = Hotel::factory()->create(['slug' => 'first-class-lodge', 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'default_locale' => 'fr']);
    $this->outlet = restaurant($this->hotel);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->chef = staffMember($this->hotel, SystemRole::RestaurantBar);

    [$this->section, $this->poulet, $this->omelette] = ($this->in)(function (): array {
        App\Domains\Hotel\Models\HotelSettings::current()->update(['enabled_locales' => ['fr', 'en']]);
        ServicePeriod::query()->create(['outlet_id' => $this->outlet->id, 'weekday' => 5, 'name' => ['fr' => 'Déjeuner', 'en' => 'Lunch'], 'starts_at' => '12:00', 'ends_at' => '15:00', 'slot_minutes' => 30, 'last_seating_minutes' => 60]);
        $section = MenuSection::query()->create(['outlet_id' => $this->outlet->id, 'name' => ['fr' => 'Plats', 'en' => 'Mains']]);

        return [
            $section,
            MenuItem::query()->create(['menu_section_id' => $section->id, 'name' => ['fr' => 'Poulet braisé', 'en' => 'Braised chicken'], 'price_minor' => 5000, 'allergens' => ['peanuts'], 'labels' => ['spicy'], 'active' => true]),
            MenuItem::query()->create(['menu_section_id' => $section->id, 'name' => ['fr' => 'Omelette'], 'price_minor' => 2000, 'period_names' => ['Déjeuner'], 'active' => true]),
        ];
    });
    $this->catalog = fn (?CarbonImmutable $at = null): array => ($this->in)(fn () => app(MenuCatalog::class)->forGuests($this->outlet, 'fr', $at));
    $this->names = fn (array $menu): array => collect($menu['sections'])->flatMap(fn ($s) => collect($s['items'])->pluck('name'))->all();
});

it('shows the price the guest pays, with food and drink taxes', function (): void {
    expect(($this->catalog)()['sections'][0]['items'][0]['price']->getMinorAmount()->toInt())->toBe(5000);

    ($this->in)(fn () => TaxRate::query()->create(['name' => ['fr' => 'TVA'], 'type' => 'percentage', 'percentage_bps' => 1000, 'inclusive' => false, 'applies_to' => ['food_beverage'], 'active' => true]));
    ($this->in)(fn () => TaxRate::query()->create(['name' => ['fr' => 'Taxe de séjour'], 'type' => 'percentage', 'percentage_bps' => 500, 'inclusive' => false, 'applies_to' => ['rooms'], 'active' => true]));

    expect(($this->catalog)()['sections'][0]['items'][0]['price']->getMinorAmount()->toInt())->toBe(5500);
});

it('shows only dishes served at the current service', function (): void {
    expect(($this->names)(($this->catalog)()))->toBe(['Poulet braisé'])
        ->and(($this->names)(($this->catalog)(CarbonImmutable::parse('2026-11-13 12:30', 'Africa/Abidjan'))))->toBe(['Poulet braisé', 'Omelette'])
        ->and(($this->names)(($this->in)(fn () => app(MenuCatalog::class)->forGuests($this->outlet, 'fr', allServices: true))))->toBe(['Poulet braisé', 'Omelette']);
});

it('takes a dish off for today only', function (): void {
    asStaffOf($this->hotel, $this->chef)->put(route('hotel.restaurant.menu.items.today', $this->poulet), ['available' => false])->assertRedirect();
    expect(($this->names)(($this->catalog)()))->toBe([]);

    $this->travelTo('2026-11-14 19:30:00');
    expect(($this->names)(($this->catalog)()))->toBe(['Poulet braisé']);
});

it('lets the restaurant team edit dishes, with price changes audited', function (): void {
    asStaffOf($this->hotel, $this->chef)->post(route('hotel.restaurant.menu.items.store'), [
        'menu_section_id' => $this->section->id, 'name' => ['fr' => 'Attiéké poisson', 'en' => 'Attiéké with fish'], 'description' => ['fr' => 'Poisson braisé'],
        'price' => 4500, 'labels' => [], 'allergens' => ['fish'], 'period_names' => '', 'active' => true,
    ])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->chef)->put(route('hotel.restaurant.menu.items.update', $this->poulet), [
        'name' => ['fr' => 'Poulet braisé'], 'price' => 5500, 'labels' => ['spicy'], 'allergens' => ['peanuts'], 'period_names' => '', 'active' => true,
    ])->assertSessionHasNoErrors();

    expect(($this->in)(fn () => MenuItem::query()->where('price_minor', 4500)->sole()->allergens))->toBe(['fish'])
        ->and(AuditLog::query()->where('event', 'menu.price_changed')->sole()->properties['new'])->toBe(5500);

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.restaurant.menu'))->assertForbidden();
    asStaffOf($this->hotel, $this->chef)->get(route('hotel.restaurant.menu'))->assertOk()->assertInertia(fn ($page) => $page->has('sections.0.items', 3));
});

it('publishes a public menu page with photos and a QR code, and no personal data', function (): void {
    bookTable($this->hotel, $this->outlet, '2026-11-13', '21:00', 2);
    asStaffOf($this->hotel, $this->chef)->post(route('hotel.restaurant.menu.items.photo', $this->poulet), ['photo' => UploadedFile::fake()->image('poulet.jpg', 400, 300)])->assertSessionHasNoErrors();
    $this->flushSession();

    $this->get('/m/first-class-lodge/restaurant')
        ->assertOk()
        ->assertSee('Poulet braisé')
        ->assertSee('Arachides')
        ->assertDontSee('Omelette')
        ->assertDontSee('Koné');
    $this->get('/m/first-class-lodge/restaurant?lang=en&all=1')->assertOk()->assertSee('Braised chicken')->assertSee('Omelette');
    $this->get("/m/first-class-lodge/restaurant/photos/{$this->poulet->id}")->assertOk();
    $this->get("/m/first-class-lodge/restaurant/photos/{$this->omelette->id}")->assertNotFound();

    $other = Hotel::factory()->create(['slug' => 'other-hotel']);
    $this->get('/m/other-hotel/restaurant')->assertNotFound();
    $this->get("/m/other-hotel/restaurant/photos/{$this->poulet->id}")->assertNotFound();

    asStaffOf($this->hotel, $this->chef)->get(route('hotel.restaurant.menu.qr', $this->outlet))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
});
