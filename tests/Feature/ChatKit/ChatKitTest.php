<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\BookingRequests\BookingRequest;
use App\Domains\BookingRequests\BookingRequestService;
use App\Domains\ChatKit\ChatKit;
use App\Domains\ChatKit\Models\ChatKitSettings;
use App\Domains\ChatKit\Models\ChatSource;
use App\Domains\Conversations\Jobs\ReplyToGuest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\StaffAlerts\Morning\MorningSummary;
use App\Domains\StaffAlerts\Morning\MorningSummaryText;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../WhatsApp/helpers.php';
require_once __DIR__.'/../Assistant/helpers.php';
require_once __DIR__.'/../Access/helpers.php';

beforeEach(function (): void {
    configureMeta();
    Mail::fake();
    Queue::fake([ReplyToGuest::class]);
    $this->travelTo('2026-11-18 10:00:00'); // a Wednesday
    ['hotel' => $this->hotel, 'exe' => $this->exe] = assistantHotel();
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    ($this->in)(function (): void {
        WhatsAppAccount::query()->create(['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']);
        HotelSettings::current()->update(['whatsapp_display_e164' => '+2250500000000', 'phone_e164' => '+2252736000000']);
    });
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.K'.bin2hex(random_bytes(5))]]])]);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->reception = staffMember($this->hotel, SystemRole::Reception);
});

function kitSource(object $test, string $code): ChatSource
{
    return ($test->in)(function () use ($code): ChatSource {
        app(ChatKit::class)->ensureDefaults();

        return ChatSource::query()->where('code', $code)->sole();
    });
}

it('gives the owner ready links, with the greeting and the source code', function (): void {
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.whatsapp-kit'))->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('number', '2250500000000')
            ->where('sources.0.code', 'R1')
            ->where('sources.0.short_url', route('kit.go', [$this->hotel->slug, 'R1']))
            ->where('sources.0.whatsapp_url', 'https://wa.me/2250500000000?text='.rawurlencode('Bonjour, je voudrais réserver une chambre. #R1'))
            ->where('snippet', '<script src="'.route('kit.button', $this->hotel->slug).'" defer></script>')
            ->has('sources', 7));

    asStaffOf($this->hotel, $this->reception)->get(route('hotel.whatsapp-kit'))->assertForbidden();
});

it('counts the click of a short link and opens WhatsApp with the message ready', function (): void {
    $source = kitSource($this, 'R1');

    $page = $this->get(route('kit.go', [$this->hotel->slug, 'r1']))->assertOk();
    $page->assertSee('https://wa.me/2250500000000?text='.rawurlencode('Bonjour, je voudrais réserver une chambre. #R1'), false)
        ->assertSee('+2250500000000')
        ->assertSee('tel:+2252736000000', false);

    $this->get(route('kit.go', [$this->hotel->slug]))->assertOk()->assertSee('%23L', false);
    $this->get(route('kit.go', [$this->hotel->slug, 'ZZ9']))->assertOk()->assertSee('%23L', false);
    $this->get(route('kit.go', [$this->hotel->slug, 'R1', 'lang' => 'en']))->assertOk()->assertSee(rawurlencode('Hello, I would like to book a room. #R1'), false);

    expect(($this->in)(fn () => $source->refresh()->clicks))->toBe(2)
        ->and($page->headers->getCookies())->toBe([]);
    $this->get(route('kit.go', ['no-such-hotel', 'R1']))->assertNotFound();
});

it('tags the conversation with the source of its first message and removes the code', function (): void {
    $source = kitSource($this, 'R1');

    webhookPost($this, [inbound('wamid.K1', message: ['text' => ['body' => 'Bonjour, je voudrais réserver une chambre. #R1']])]);
    webhookPost($this, [inbound('wamid.K2', message: ['text' => ['body' => 'Bonjour #G']])]);
    webhookPost($this, [inbound('wamid.K3', message: ['text' => ['body' => 'Mon code promo #XYZ9']])]);

    $conversation = ($this->in)(fn () => Conversation::query()->sole());
    $bodies = ($this->in)(fn () => Message::query()->where('direction', 'in')->orderBy('sent_at')->orderBy('id')->pluck('body')->all());

    expect($conversation->chat_source_id)->toBe($source->id)
        ->and($bodies)->toBe(['Bonjour, je voudrais réserver une chambre.', 'Bonjour', 'Mon code promo #XYZ9']);
});

it('shows chats and bookings by source, and Monday\'s summary sums up the week', function (): void {
    $source = kitSource($this, 'G');
    webhookPost($this, [inbound('wamid.K4', message: ['text' => ['body' => 'Bonjour, je voudrais réserver une chambre. #G']])]);
    $conversation = ($this->in)(fn () => Conversation::query()->with('contact')->sole());
    $request = ($this->in)(fn (): BookingRequest => app(BookingRequestService::class)->submit(
        $conversation,
        new StayRequest($this->exe->id, CarbonImmutable::parse('2026-12-02'), CarbonImmutable::parse('2026-12-04'), adults: 2),
        'Awa Koné',
    ));
    asStaffOf($this->hotel, $this->reception)->post(route('hotel.booking-requests.approve', $request), ['edit' => false, 'acknowledge_price' => false]);

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.whatsapp-kit'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('sources.4.code', 'G')->where('sources.4.chats', 1)->where('sources.4.bookings', 1));

    $this->travelTo('2026-11-23 07:00:00'); // Monday
    $text = ($this->in)(fn () => MorningSummaryText::message(app(MorningSummary::class)->build(), 'Awa', 'fr', true));
    expect($text)->toContain('Semaine passée sur WhatsApp : 1 conversation (Google Maps 1) · 1 réservation');

    $this->travelTo('2026-11-24 07:00:00'); // Tuesday: not repeated
    $text = ($this->in)(fn () => MorningSummaryText::message(app(MorningSummary::class)->build(), 'Awa', 'fr', true));
    expect($text)->not->toContain('Semaine passée');
});

it('serves the website button script in the hotel colours, with its hours', function (): void {
    ($this->in)(fn () => ChatKitSettings::current()->update(['color' => '#123abc', 'online_from' => '08:00', 'online_until' => '20:00', 'bubble' => ['fr' => 'Une chambre ? Écrivez-nous']]));

    $response = $this->get(route('kit.button', $this->hotel->slug))->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($response->getContent())->toContain('#123abc')->toContain('"from":"08:00"')->toContain('Africa/Abidjan')
        ->toContain(route('kit.go', [$this->hotel->slug, 'W']))
        ->toContain('Une chambre ? Écrivez-nous')
        ->not->toContain('<script');
});

it('makes print-ready posters, QR codes, and new sources with their own code', function (): void {
    Storage::fake('local');
    $source = kitSource($this, 'R1');

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp-kit.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 300, 120)])->assertRedirect();

    foreach (['a4', 'a5'] as $format) {
        $pdf = asStaffOf($this->hotel, $this->owner)->get(route('hotel.whatsapp-kit.poster', [$source, 'format' => $format, 'lang' => 'fr']))->assertOk();
        expect($pdf->headers->get('Content-Type'))->toBe('application/pdf')
            ->and(substr((string) $pdf->getContent(), 0, 4))->toBe('%PDF');
    }

    $qr = asStaffOf($this->hotel, $this->owner)->get(route('hotel.whatsapp-kit.qr', $source))->assertOk();
    expect($qr->headers->get('Content-Type'))->toBe('image/svg+xml')->and((string) $qr->getContent())->toContain('<svg');

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.whatsapp-kit.sources.store'), ['kind' => 'poster', 'name' => 'Affiche des chambres', 'intent' => 'tonight'])->assertRedirect();
    $new = ($this->in)(fn () => ChatSource::query()->where('name', 'Affiche des chambres')->sole());
    expect($new->code)->toBe('P1')
        ->and(($this->in)(fn () => app(ChatKit::class)->prefilled($new, 'fr')))->toBe('Bonjour, avez-vous une chambre pour ce soir ? #P1');

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.whatsapp-kit.settings'), ['color' => 'red', 'online_from' => '08:00', 'online_until' => '20:00'])->assertSessionHasErrors('color');
});
