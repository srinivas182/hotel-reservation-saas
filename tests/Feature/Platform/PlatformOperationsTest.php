<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Audit;
use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Platform\Models\HotelNote;
use App\Domains\Platform\PlatformAbility;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Billing/helpers.php';

function platformUser(PlatformRole $role): User
{
    return User::factory()->platformAdmin($role)->withTwoFactor()->create();
}

it('protects every platform route with an ability', function (): void {
    $unguarded = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r): bool => str_starts_with((string) $r->getName(), 'platform.'))
        ->reject(fn ($r): bool => collect($r->gatherMiddleware())->contains(fn ($m): bool => is_string($m) && str_starts_with($m, 'platform.can:')))
        ->map(fn ($r): string => (string) $r->getName())->values()->all();

    expect($unguarded)->toBe([]);
});

it('gives each platform role what it needs', function (PlatformRole $role, array $can, array $cannot): void {
    foreach ($can as $ability) {
        expect(PlatformAbility::from($ability)->roles())->toContain($role);
    }
    foreach ($cannot as $ability) {
        expect(PlatformAbility::from($ability)->roles())->not->toContain($role);
    }
})->with([
    'operations' => [PlatformRole::Operations, ['hotels.view', 'hotels.manage', 'golive', 'readiness', 'audit.view', 'signups.codes', 'billing.view'], ['billing.manage', 'catalog', 'configuration', 'audit.export', 'signups.configure']],
    'finance' => [PlatformRole::Finance, ['hotels.view', 'billing.view', 'billing.manage', 'notes'], ['hotels.manage', 'catalog', 'golive', 'signups.view', 'audit.view']],
    'sales' => [PlatformRole::Sales, ['hotels.view', 'signups.view', 'signups.codes', 'notes'], ['hotels.manage', 'billing.view', 'golive', 'signups.configure', 'audit.view']],
]);

it('enforces the roles on the screens', function (): void {
    $hotel = Hotel::factory()->create();
    $plan = billingPlan('pro');

    $this->actingAs(platformUser(PlatformRole::Finance))->get(route('platform.billing.index'))->assertOk();
    $this->actingAs(platformUser(PlatformRole::Finance))->get(route('platform.hotels.create'))->assertForbidden();
    $this->actingAs(platformUser(PlatformRole::Sales))->get(route('platform.signups.index'))->assertOk();
    $this->actingAs(platformUser(PlatformRole::Sales))->get(route('platform.billing.index'))->assertForbidden();
    $this->actingAs(platformUser(PlatformRole::Operations))->put(route('platform.billing.plans.update', $plan), [])->assertForbidden();
    $this->actingAs(platformUser(PlatformRole::Operations))->get(route('platform.hotels.show', $hotel))->assertOk();
    $this->actingAs(platformUser(PlatformRole::Operations))->get(route('platform.users.index'))->assertForbidden();

    foreach (PlatformRole::cases() as $role) {
        $this->actingAs(platformUser($role))->get(route('platform.home'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('auth.platform', PlatformAbility::for($role)));
    }
    $this->actingAs(User::factory()->create())->get(route('platform.home'))->assertForbidden();
});

it('shows the hotels that need attention, filtered by country', function (): void {
    Cache::flush();
    $broken = Hotel::factory()->create(['name' => 'Hôtel A', 'country_code' => 'CI']);
    $overdue = Hotel::factory()->create(['name' => 'Hôtel B', 'country_code' => 'SN', 'currency' => 'XOF']);
    $asking = Hotel::factory()->create(['name' => 'Hôtel C', 'country_code' => 'CI']);
    Hotel::factory()->create(['name' => 'Hôtel D', 'country_code' => 'CI']);

    app(TenantContext::class)->run($broken, fn () => WhatsAppAccount::query()->create(['waba_id' => '1', 'phone_number_id' => 'P1', 'status' => AccountStatus::Disconnected, 'token' => 't']));
    app(TenantContext::class)->run($asking, fn () => OnboardingProgress::current()->forceFill(['golive_requested_at' => now()])->save());
    $entity = BillingEntity::query()->create(['name' => 'CNH', 'legal_name' => 'CNH', 'country_code' => 'CI', 'address' => 'Abidjan', 'email' => 'f@cnh.example', 'invoice_prefix' => 'CNH', 'is_default' => true]);
    Invoice::query()->create(['hotel_id' => $overdue->id, 'billing_entity_id' => $entity->id, 'number' => 'CNH-1', 'kind' => 'invoice', 'currency' => 'XOF', 'subtotal_minor' => 1000, 'tax_minor' => 0, 'total_minor' => 1000, 'taxes' => [], 'issuer' => [], 'customer' => [], 'issued_on' => now()->subDays(20), 'due_on' => now()->subDays(10)]);

    $ops = platformUser(PlatformRole::Operations);
    $this->actingAs($ops)->get(route('platform.home'))->assertInertia(fn ($page) => $page
        ->has('attention', 3)
        ->where('attention', fn ($rows) => collect($rows)->pluck('issues.0.key')->sort()->values()->all() === ['golive_request', 'overdue_invoice', 'whatsapp_disconnected']));

    Cache::flush();
    $this->actingAs($ops)->get(route('platform.home', ['country' => 'SN']))->assertInertia(fn ($page) => $page->has('attention', 1)->where('attention.0.hotel', 'Hôtel B')->where('business.hotels', 1));
});

it('keeps internal notes on the platform side only', function (): void {
    $hotel = Hotel::factory()->create();
    $owner = staffMember($hotel, SystemRole::Owner);
    $sales = platformUser(PlatformRole::Sales);

    $this->actingAs($sales)->post(route('platform.hotels.notes.store', $hotel), ['body' => 'Le propriétaire préfère être appelé le matin.'])->assertSessionHasNoErrors();
    $note = HotelNote::query()->sole();
    expect(Illuminate\Support\Facades\DB::table('hotel_notes')->value('body'))->not->toContain('matin');

    $this->actingAs(platformUser(PlatformRole::Operations))->get(route('platform.hotels.show', $hotel))->assertInertia(fn ($page) => $page->where('notes.0.body', 'Le propriétaire préfère être appelé le matin.')->where('notes.0.mine', false));
    $this->actingAs(platformUser(PlatformRole::Operations))->delete(route('platform.hotels.notes.destroy', [$hotel, $note]))->assertForbidden();

    $this->flushSession();
    foreach (['hotel.today', 'hotel.settings.edit', 'audit.index'] as $route) {
        if (Route::has($route)) {
            asStaffOf($hotel, $owner)->get(route($route))->assertDontSee('matin');
        }
    }
});

it('searches the audit log and lets only super admins export it', function (): void {
    $hotel = Hotel::factory()->create();
    $ops = platformUser(PlatformRole::Operations);
    app(TenantContext::class)->run($hotel, fn () => Audit::record('billing.payment_recorded', 'Invoice payment recorded', subject: $hotel, causer: $ops));
    Audit::record('platform.note_added', 'Platform note added', subject: $hotel, causer: $ops);

    $this->actingAs($ops)->get(route('platform.audit', ['hotel' => $hotel->id, 'event' => 'billing.']))
        ->assertOk()->assertInertia(fn ($page) => $page->has('entries', 1)->where('entries.0.event', 'billing.payment_recorded')->where('canExport', false));
    $this->actingAs($ops)->get(route('platform.audit', ['user' => $ops->email]))->assertInertia(fn ($page) => $page->where('pagination.total', 2));
    $this->actingAs($ops)->get(route('platform.audit.export'))->assertForbidden();

    $csv = $this->actingAs(platformUser(PlatformRole::SuperAdmin))->get(route('platform.audit.export', ['event' => 'platform.']))->assertOk()->streamedContent();
    expect($csv)->toContain('platform.note_added')->not->toContain('billing.payment_recorded');
});
