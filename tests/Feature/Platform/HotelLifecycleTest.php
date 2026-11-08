<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\Guest;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Platform\Lifecycle\HotelClosure;
use App\Domains\Platform\Lifecycle\Mail\ClosureMail;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Billing/helpers.php';
require_once __DIR__.'/../Bookings/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    Illuminate\Support\Facades\Storage::fake('local');
    $this->travelTo('2026-11-01 10:00:00');
    $this->hotel = Hotel::factory()->create(['name' => 'Hôtel Le Baobab', 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan']);
    $this->other = Hotel::factory()->create(['name' => 'Autre hôtel']);
    $this->owner = staffMember($this->hotel, SystemRole::Owner, ['password' => bcrypt('secret-password')]);
    $this->owner->forceFill(['two_factor_secret' => encrypt('s'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();
    $this->hotel->forceFill(['owner_user_id' => $this->owner->id])->save();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);

    $category = roomCategory($this->hotel, 'STD', 3);
    rateFor($this->hotel, $category);
    book($this->hotel, $category, '2026-11-10', '2026-11-12');
    $otherCategory = roomCategory($this->other, 'STD', 2);
    rateFor($this->other, $otherCategory);
    book($this->other, $otherCategory, '2026-11-10', '2026-11-12');
    subscribe($this->hotel, billingPlan('pro'));
    Illuminate\Support\Facades\Storage::disk('local')->put('whatsapp/'.$this->hotel->id.'/voice.ogg', 'OGGS');
    Illuminate\Support\Facades\Storage::disk('local')->put('hotels/'.$this->hotel->id.'/menu/dish.jpg', 'JPG');
    Illuminate\Support\Facades\Storage::disk('local')->put('whatsapp/'.$this->other->id.'/keep.ogg', 'OGGS');
    ($this->in)(fn () => WhatsAppAccount::query()->create(['waba_id' => '1', 'phone_number_id' => 'P1', 'status' => AccountStatus::Connected, 'token' => 'secret-token']));
    ($this->in)(fn () => HotelAssistantSettings::current()->forceFill(['mode' => AssistantMode::Supervised])->save());
    $entity = BillingEntity::query()->create(['name' => 'CNH', 'legal_name' => 'CNH', 'country_code' => 'CI', 'address' => 'Abidjan', 'email' => 'f@cnh.example', 'invoice_prefix' => 'CNH', 'is_default' => true]);
    Invoice::query()->create(['hotel_id' => $this->hotel->id, 'billing_entity_id' => $entity->id, 'number' => 'CNH-1', 'kind' => 'invoice', 'currency' => 'XOF', 'subtotal_minor' => 1000, 'tax_minor' => 0, 'total_minor' => 1000, 'paid_minor' => 1000, 'taxes' => [], 'issuer' => [], 'customer' => [], 'issued_on' => now(), 'due_on' => now()]);
});

function closeAsOwner($test): Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->owner)->post(route('account.hotels.close', $test->hotel), ['password' => 'secret-password', 'confirm' => 'Hôtel Le Baobab', 'reason' => 'Vente de l’hôtel']);
}

it('lets an owner add another hotel with its own trial and setup', function (): void {
    billingPlan('essentiel')->update(['is_default' => true]);
    $this->owner->markEmailAsVerified();

    $this->actingAs($this->owner)->post(route('account.hotels.store'), ['hotel_name' => 'Hôtel du Lac', 'country_code' => 'SN', 'city' => 'Saint-Louis', 'rooms' => 12])
        ->assertRedirect(route('hotel.setup'));

    $new = Hotel::query()->where('name', 'Hôtel du Lac')->sole();
    expect($new->owner_user_id)->toBe($this->owner->id)->and($new->currency)->toBe('XOF')->and($new->timezone)->toBe('Africa/Dakar')
        ->and(app(TenantContext::class)->run($new, fn () => Subscription::query()->sole()->status))->toBe('trialing');
    $this->actingAs($this->owner)->get(route('account.hotels'))->assertInertia(fn ($page) => $page->has('hotels', 2));
});

it('closes a hotel: no access, no assistant, no WhatsApp, no invoices, with a full data export', function (): void {
    $this->actingAs($this->owner)->post(route('account.hotels.close', $this->hotel), ['password' => 'wrong', 'confirm' => 'Hôtel Le Baobab'])->assertSessionHasErrors('password');
    $this->actingAs($this->owner)->post(route('account.hotels.close', $this->hotel), ['password' => 'secret-password', 'confirm' => 'Hotel'])->assertSessionHasErrors('confirm');
    closeAsOwner($this)->assertRedirect(route('account.hotels'));

    $hotel = $this->hotel->fresh();
    expect($hotel->status)->toBe(HotelStatus::Closed)
        ->and(($this->in)(fn () => Subscription::query()->sole()->status))->toBe('cancelled')
        ->and(($this->in)(fn () => HotelAssistantSettings::current()->mode))->toBe(AssistantMode::Off)
        ->and(($this->in)(fn () => WhatsAppAccount::query()->sole()->token))->toBeNull();
    Mail::assertQueued(ClosureMail::class, fn (ClosureMail $m): bool => $m->kind === 'closed');

    $receptionist = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $receptionist)->get(route('hotel.today'))->assertForbidden();

    $download = $this->actingAs($this->owner)->get(route('account.hotels.export', $this->hotel))->assertOk();
    $zip = new ZipArchive;
    $zip->open($download->getFile()->getPathname());
    $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i));
    expect($names)->toContain('data/bookings.json')->toContain('data/guests.csv')->toContain('hotel.json')
        ->toContain('files/whatsapp/'.$this->hotel->id.'/voice.ogg')->toContain('files/hotels/'.$this->hotel->id.'/menu/dish.jpg')
        ->not->toContain('data/whatsapp_accounts.json')
        ->and((string) $zip->getFromName('data/bookings.json'))->not->toContain(Hotel::query()->where('name', 'Autre hôtel')->value('id'));

    $this->actingAs(staffMember($this->other, SystemRole::Owner))->get(route('account.hotels.export', $this->hotel))->assertForbidden();
});

it('reminds the owner, then deletes the data 90 days later, keeping invoices and other hotels', function (): void {
    closeAsOwner($this);

    $this->travelTo('2026-12-02 04:00:00');
    $this->artisan('hotels:closures');
    $this->travelTo('2027-01-23 04:00:00');
    $this->artisan('hotels:closures');
    $this->artisan('hotels:closures');
    expect(Mail::queued(ClosureMail::class)->map(fn ($m) => $m->kind)->values()->all())->toBe(['closed', 'reminder_30', 'reminder_7']);

    $this->travelTo('2027-01-30 04:00:00');
    $this->artisan('hotels:closures');

    expect(Booking::query()->withoutGlobalScopes()->where('hotel_id', $this->hotel->id)->count())->toBe(0)
        ->and(Guest::query()->withoutGlobalScopes()->where('hotel_id', $this->hotel->id)->count())->toBe(0)
        ->and(Booking::query()->withoutGlobalScopes()->where('hotel_id', $this->other->id)->count())->toBe(1)
        // No orphans left in tables without a hotel_id (rooms of the deleted bookings).
        ->and(Illuminate\Support\Facades\DB::table('booking_rooms')->whereNotIn('booking_id', Booking::query()->withoutGlobalScopes()->select('id'))->count())->toBe(0)
        ->and(Invoice::query()->where('hotel_id', $this->hotel->id)->count())->toBe(1)
        ->and($this->hotel->fresh()->name)->toStartWith('Deleted hotel')
        ->and(Illuminate\Support\Facades\Storage::disk('local')->allFiles())->toBe(['whatsapp/'.$this->other->id.'/keep.ogg'])
        ->and(HotelClosure::query()->sole()->purged_at)->not->toBeNull();
});

it('reopens a closed hotel before deletion', function (): void {
    closeAsOwner($this);
    $this->actingAs($this->owner)->post(route('account.hotels.reopen', $this->hotel))->assertRedirect();

    expect($this->hotel->fresh()->status)->not->toBe(HotelStatus::Closed)->and(HotelClosure::query()->sole()->reopened_at)->not->toBeNull();
    $this->travelTo('2027-03-01 04:00:00');
    $this->artisan('hotels:closures');
    expect(Booking::query()->withoutGlobalScopes()->where('hotel_id', $this->hotel->id)->count())->toBe(1);
});

it('lets only super admins close a hotel from the platform, with a reason', function (): void {
    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create())->post(route('platform.hotels.close', $this->hotel), ['reason' => 'x'])->assertForbidden();
    $admin = User::factory()->platformAdmin()->withTwoFactor()->create();
    $this->actingAs($admin)->post(route('platform.hotels.close', $this->hotel), [])->assertSessionHasErrors('reason');
    $this->actingAs($admin)->post(route('platform.hotels.close', $this->hotel), ['reason' => 'Fraude'])->assertSessionHasNoErrors();

    expect(HotelClosure::query()->sole()->by_role)->toBe('platform');
});
