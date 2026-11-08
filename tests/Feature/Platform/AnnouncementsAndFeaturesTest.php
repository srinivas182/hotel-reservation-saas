<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Engine\HotelAssistantAgent;
use App\Domains\Assistant\Engine\ToolContext;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Platform\Announcements\Mail\AnnouncementMail;
use App\Domains\Platform\Features\Feature;
use App\Domains\Platform\Features\Features;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';

beforeEach(function (): void {
    $this->ops = User::factory()->platformAdmin(PlatformRole::Operations)->withTwoFactor()->create();
    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

function announce(User $by, array $overrides = []): void
{
    test()->actingAs($by)->post(route('platform.announcements.store'), [
        'title' => ['fr' => 'Nouveau : commandes WhatsApp', 'en' => 'New: WhatsApp orders'], 'body' => ['fr' => 'Vos clients peuvent commander.', 'en' => 'Guests can order.'],
        'level' => 'info', 'countries' => [], 'plans' => [], 'hotels' => [],
        'starts_at' => now()->subHour()->toDateTimeString(), 'ends_at' => now()->addDay()->toDateTimeString(), 'email' => false, ...$overrides,
    ])->assertSessionHasNoErrors();
}

it('shows announcements to their audience between their dates, and lets info banners be hidden', function (): void {
    $ci = Hotel::factory()->create(['country_code' => 'CI']);
    $sn = Hotel::factory()->create(['country_code' => 'SN']);
    $ciOwner = staffMember($ci, SystemRole::Owner);
    $snOwner = staffMember($sn, SystemRole::Owner);

    announce($this->ops, ['countries' => ['SN']]);
    announce($this->ops, ['level' => 'maintenance', 'title' => ['fr' => 'Maintenance dimanche'], 'body' => ['fr' => 'De 2 h à 3 h.']]);
    announce($this->ops, ['starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString()]);
    $this->flushSession();

    asStaffOf($ci, $ciOwner)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->has('announcements', 1)->where('announcements.0.level', 'maintenance'));
    asStaffOf($sn, $snOwner)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->has('announcements', 2));

    $info = App\Domains\Platform\Announcements\Announcement::query()->where('level', 'info')->orderBy('id')->first();
    $maintenance = App\Domains\Platform\Announcements\Announcement::query()->where('level', 'maintenance')->sole();
    asStaffOf($sn, $snOwner)->post(route('hotel.announcements.dismiss', $info))->assertRedirect();
    asStaffOf($sn, $snOwner)->post(route('hotel.announcements.dismiss', $maintenance))->assertForbidden();
    asStaffOf($sn, $snOwner)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->has('announcements', 1));
});

it('emails an announcement to the owners it targets, once', function (): void {
    Mail::fake();
    $a = Hotel::factory()->create(['country_code' => 'SN', 'owner_user_id' => User::factory()->create(['email' => 'sn@hotel.example'])->id]);
    Hotel::factory()->create(['country_code' => 'CI', 'owner_user_id' => User::factory()->create(['email' => 'ci@hotel.example'])->id]);
    announce($this->ops, ['countries' => ['SN'], 'email' => true]);

    $this->artisan('announcements:send');
    $this->artisan('announcements:send');

    Mail::assertQueuedCount(1);
    Mail::assertQueued(AnnouncementMail::class, fn (AnnouncementMail $m): bool => $m->hasTo('sn@hotel.example'));
    expect($a)->not->toBeNull();
});

it('turns features on for everyone, off, or for chosen hotels, alongside plan modules', function (): void {
    ['hotel' => $hotel] = assistantHotel();
    $other = Hotel::factory()->create(['country_code' => 'SN']);
    $tools = fn (Hotel $h): array => app(TenantContext::class)->run($h, fn () => collect((new HotelAssistantAgent('x', [], new ToolContext('fr')))->tools())->map(fn ($t) => $t->name())->all());
    $on = fn (Hotel $h, Feature $f): bool => app(TenantContext::class)->run($h, function () use ($f): bool {
        app(Features::class)->forget();

        return app(Features::class)->enabled($f);
    });

    expect($on($hotel, Feature::WhatsAppEvents))->toBeTrue();

    $this->actingAs($this->admin)->put(route('platform.features.update', 'whatsapp_events'), ['mode' => 'off'])->assertSessionHasNoErrors();
    expect($on($hotel, Feature::WhatsAppEvents))->toBeFalse()->and($tools($hotel))->not->toContain('event_enquiry');

    $this->actingAs($this->admin)->put(route('platform.features.update', 'whatsapp_events'), ['mode' => 'targeted', 'hotels' => [$hotel->id], 'countries' => [], 'plans' => []]);
    expect($on($hotel, Feature::WhatsAppEvents))->toBeTrue()->and($on($other, Feature::WhatsAppEvents))->toBeFalse()
        ->and($tools($hotel))->toContain('event_enquiry');

    $this->actingAs($this->admin)->put(route('platform.features.update', 'whatsapp_restaurant'), ['mode' => 'targeted', 'countries' => ['SN']]);
    expect($tools($hotel))->not->toContain('book_table')->not->toContain('place_order');

    $this->actingAs($this->ops)->put(route('platform.features.update', 'voice_notes'), ['mode' => 'off'])->assertForbidden();
});

it('keeps automatic mode supervised while the feature is off', function (): void {
    ['hotel' => $hotel] = assistantHotel();
    $owner = staffMember($hotel, SystemRole::Owner);
    app(TenantContext::class)->run($hotel, fn () => HotelAssistantSettings::current()->forceFill(['mode' => AssistantMode::Automatic])->save());
    $this->actingAs($this->admin)->put(route('platform.features.update', 'automatic_mode'), ['mode' => 'off']);

    expect(app(TenantContext::class)->run($hotel, function () {
        app(Features::class)->forget();

        return HotelAssistantSettings::current()->effectiveMode();
    }))->toBe(AssistantMode::Supervised);

    app(TenantContext::class)->run($hotel, fn () => HotelAssistantSettings::current()->forceFill(['mode' => AssistantMode::Supervised])->save());
    $this->flushSession();
    asStaffOf($hotel, $owner)->put(route('hotel.assistant.update'), [
        'mode' => 'automatic', 'tone' => 'formal', 'languages' => ['fr'], 'daily_budget' => 5, 'kill_switch' => false,
        'request_hold_minutes' => 120, 'deposit_required' => false, 'deposit_percent' => 30, 'deposit_hours' => 24, 'silence_minutes' => 20,
    ])->assertSessionHasErrors('mode');
    expect(app(TenantContext::class)->run($hotel, fn () => HotelAssistantSettings::current()->mode))->toBe(AssistantMode::Supervised);
});
