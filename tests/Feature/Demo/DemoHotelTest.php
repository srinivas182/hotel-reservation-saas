<?php

declare(strict_types=1);

use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Message;
use App\Domains\Demo\DemoHotels;
use App\Domains\Demo\Mail\DemoLinkMail;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Site\Models\Lead;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../WhatsApp/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    $this->sales = User::factory()->platformAdmin(PlatformRole::Sales)->withTwoFactor()->create();
});

function inDemo(Hotel $hotel, callable $callback): mixed
{
    return app(TenantContext::class)->run($hotel, $callback);
}

it('builds a realistic demo hotel with a manager login, no subscription and no real WhatsApp', function (): void {
    $made = app(DemoHotels::class)->create('Hôtel Démo');
    $hotel = $made['hotel'];

    expect($hotel->is_demo)->toBeTrue()
        ->and(DB::table('subscriptions')->where('hotel_id', $hotel->id)->count())->toBe(0)
        ->and(inDemo($hotel, fn () => RoomCategory::query()->count()))->toBe(4)
        ->and(inDemo($hotel, fn () => Booking::query()->count()))->toBe(1)
        ->and(inDemo($hotel, fn () => $made['user']->can('bookings.view') && ! $made['user']->can('roles.manage')))->toBeTrue()
        ->and($made['user']->requiresTwoFactor())->toBeFalse();

    Http::fake();
    $wamid = app(GraphClient::class)->sendMessage('DEMO-'.$hotel->id, 'demo', '2250700000001', ['type' => 'text', 'text' => ['body' => 'x']]);
    expect($wamid)->toStartWith('wamid.DEMO');
    Http::assertNothingSent();
});

it('plays a scenario into the demo hotel as a WhatsApp message, and shows it live', function (): void {
    Queue::fake();
    $made = app(DemoHotels::class)->create('Hôtel Démo');
    $hotel = $made['hotel'];

    $wa = asStaffOf($hotel, $made['user'])->postJson(route('hotel.demo.play'), ['scenario' => 'room'])->assertOk()->json('wa');
    Queue::assertPushed(App\Domains\WhatsApp\Webhooks\ProcessWebhookEvent::class);
    foreach (App\Domains\WhatsApp\Models\WebhookEvent::query()->pluck('id') as $id) {
        app()->call([new App\Domains\WhatsApp\Webhooks\ProcessWebhookEvent((int) $id), 'handle']);
    }

    expect(inDemo($hotel, fn () => Message::query()->where('direction', 'in')->value('body')))->toContain('chambre pour 2 personnes');
    asStaffOf($hotel, $made['user'])->getJson(route('hotel.demo.feed', ['wa' => $wa]))->assertOk()->assertJsonPath('conversation.name', 'Moussa Koné');

    // Never in a real hotel.
    $real = Hotel::factory()->create();
    asStaffOf($real, staffMember($real, App\Domains\Access\SystemRole::Manager))->postJson(route('hotel.demo.play'), ['scenario' => 'room'])->assertNotFound();
});

it('shows the prospect’s own hotel, and resets to the starting point', function (): void {
    $made = app(DemoHotels::class)->create('Hôtel Démo');
    $hotel = $made['hotel'];

    asStaffOf($hotel, $made['user'])->post(route('hotel.demo.personalise'), ['name' => 'Hôtel Teranga', 'categories' => [['name' => 'Confort', 'price' => 30000, 'rooms' => 12], ['name' => 'Suite', 'price' => 60000, 'rooms' => 2]]])->assertRedirect(route('hotel.demo'));
    expect($hotel->fresh()->name)->toBe('Hôtel Teranga')
        ->and(inDemo($hotel, fn () => RoomCategory::query()->orderBy('sort_order')->pluck('name')->map(fn ($n) => $n['fr'])->all()))->toBe(['Confort', 'Suite'])
        ->and(inDemo($hotel, fn () => App\Domains\Rooms\Models\Room::query()->count()))->toBe(14);

    $this->artisan('demo:maintain --reset')->assertSuccessful();
    expect(inDemo($hotel, fn () => RoomCategory::query()->count()))->toBe(4)
        ->and(inDemo($hotel, fn () => $made['user']->can('bookings.view')))->toBeTrue();
});

it('sends a private 48-hour demo to a lead, records when it is opened, then deletes it', function (): void {
    $lead = Lead::query()->create(['name' => 'Moussa', 'hotel_name' => 'Hôtel Teranga', 'whatsapp' => '+221771234567', 'email' => 'moussa@teranga.example', 'status' => 'new']);

    $this->actingAs($this->sales)->post(route('platform.leads.demo', $lead))->assertRedirect();
    $lead->refresh();
    $demo = Hotel::query()->findOrFail($lead->demo_hotel_id);
    expect($demo->name)->toBe('Hôtel Teranga')->and($demo->demo_expires_at?->diffInHours(now(), true))->toBeGreaterThan(47.0)->and($lead->status)->toBe('contacted');
    Mail::assertQueued(DemoLinkMail::class, fn (DemoLinkMail $m): bool => $m->hasTo('moussa@teranga.example') && str_contains($m->link, '/demo/enter/'));

    $link = Mail::queued(DemoLinkMail::class)->first()->link;
    app('auth')->forgetGuards();
    $this->flushSession();
    $this->get('/demo/enter/'.$demo->id)->assertForbidden(); // unsigned
    $this->get($link)->assertRedirect(route('hotel.demo'));
    expect($lead->fresh()->demo_opened_at)->not->toBeNull();

    $this->travel(49)->hours();
    $this->artisan('demo:maintain')->assertSuccessful();
    expect($demo->fresh()->status)->toBe(HotelStatus::Closed)
        ->and(inDemo($demo, fn () => RoomCategory::query()->count()))->toBe(0)
        ->and(User::query()->where('email', 'like', $demo->slug.'%')->count())->toBe(0);
    $this->get($link)->assertForbidden();
});

it('lets sales create the sales demo and see its password once', function (): void {
    $this->actingAs($this->sales)->post(route('platform.demo.create'))->assertSessionHas('demo_credentials');
    $this->actingAs($this->sales)->get(route('platform.demo'))->assertOk()->assertInertia(fn ($page) => $page->where('demo.name', 'Hôtel Démo'));
    $this->actingAs($this->sales)->post(route('platform.demo.create'))->assertStatus(409);
    $this->actingAs(User::factory()->platformAdmin(PlatformRole::Finance)->withTwoFactor()->create())->get(route('platform.demo'))->assertForbidden();
});
