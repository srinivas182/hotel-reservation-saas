<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Onboarding\Listing\ListingAgent;
use App\Domains\Onboarding\Listing\ListingDraft;
use App\Domains\Onboarding\Listing\SafeFetcher;
use App\Domains\Onboarding\Models\ListingImport;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Simulation\SimulatedAi;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Ai/helpers.php';
require_once __DIR__.'/../Rooms/helpers.php';

const BAOBAB_PAGE = <<<'HTML'
<html><head><title>Hôtel Le Baobab</title>
<script type="application/ld+json">{"@type":"Hotel","name":"Hôtel Le Baobab","address":{"streetAddress":"Avenue de la Paix","addressLocality":"Korhogo"},"checkinTime":"14:00"}</script>
</head><body><h1>Nos chambres</h1><p>Standard 25 000 FCFA, Suite 45 000 FCFA. Petit-déjeuner 3 000 FCFA.</p></body></html>
HTML;

function listingReply(array $overrides = []): string
{
    return (string) json_encode(array_replace([
        'profile' => ['name' => ['value' => 'Baobab', 'sure' => false], 'check_out_time' => ['value' => '12:00', 'sure' => true]],
        'rooms' => [
            ['name' => 'Standard', 'count' => 8, 'adults' => 2, 'price' => 25000, 'sure' => true],
            ['name' => 'Suite', 'count' => null, 'adults' => 3, 'price' => 45000, 'sure' => false],
        ],
        'entries' => [
            ['locale' => 'fr', 'section' => 'restaurant', 'question' => 'Petit-déjeuner ?', 'content' => 'Petit-déjeuner à 3 000 FCFA.', 'sure' => true],
            ['locale' => 'en', 'section' => 'restaurant', 'question' => 'Breakfast?', 'content' => 'Breakfast costs 3,000 FCFA.', 'sure' => true],
        ],
    ], $overrides), JSON_UNESCAPED_UNICODE);
}

beforeEach(function (): void {
    $this->travelTo('2026-11-18 10:00:00');
    Storage::fake('local');
    setUpAiModels();
    $this->hotel = Hotel::factory()->create(['name' => 'Mon hôtel', 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'country_code' => 'CI', 'default_locale' => 'fr']);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    // Every host is "public" here; the address checks have their own tests.
    $this->resolved = ['93.184.216.34'];
    app()->bind(SafeFetcher::class, fn () => new SafeFetcher(fn (string $host): array => $this->resolved));
    $this->listing = fn () => asStaffOf($this->hotel, $this->owner)->get(route('hotel.setup'))->viewData('page')['props']['listing'];
});

it('reads the website, the Maps pin and pasted text, and applies only what the owner ticked', function (): void {
    Http::fake([
        'baobab.ci/robots.txt' => Http::response('', 404),
        'baobab.ci/*' => Http::response(BAOBAB_PAGE, 200, ['Content-Type' => 'text/html; charset=utf-8']),
    ]);
    ListingAgent::fake([listingReply()]);
    roomCategory($this->hotel, 'STD', 0, ['name' => ['fr' => 'Standard', 'en' => 'Standard']]);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), [
        'website_url' => 'https://baobab.ci/',
        'maps_url' => 'https://www.google.com/maps/place/Baobab/@9.45,-5.63,17z/data=!3d9.4581234!4d-5.6295678',
        'pasted_text' => 'Parking gratuit et gardé.',
    ])->assertSessionHasNoErrors();

    ListingAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->prompt, 'Standard 25 000 FCFA') && str_contains($prompt->prompt, 'Parking gratuit') && str_contains($prompt->prompt, 'Place name: Baobab'));

    $listing = ($this->listing)();
    expect($listing['status'])->toBe('ready')
        ->and($listing['profile']->name)->toEqual(['value' => 'Hôtel Le Baobab', 'sure' => true]) // the website's own data wins
        ->and($listing['profile']->address_line1['value'])->toBe('Avenue de la Paix')
        ->and($listing['profile']->check_in_time['value'])->toBe('14:00')
        ->and($listing['profile']->location['value'])->toEqual(['latitude' => 9.4581234, 'longitude' => -5.6295678])
        ->and($listing['rooms'][0]['exists'])->toBeTrue() // "Standard" is already set up: unticked by default
        ->and($listing['rooms'][1]['sure'])->toBeFalse()
        ->and($listing['entries'])->toHaveCount(2)
        ->and($listing['current']['name'])->toBe('Mon hôtel');

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing.apply', $listing['id']), [
        'profile' => ['name', 'location', 'check_in_time'],
        'rooms' => [['index' => 1, 'name' => 'Suite Baobab', 'count' => 2, 'adults' => 3, 'price' => 45000]],
        'entries' => [0, 1],
    ])->assertSessionHasNoErrors();

    $settings = ($this->in)(fn () => HotelSettings::current());
    $suite = ($this->in)(fn () => RoomCategory::query()->where('code', '!=', 'STD')->sole());
    expect($this->hotel->fresh()->name)->toBe('Hôtel Le Baobab')
        ->and($settings->latitude)->toBe(9.4581234)
        ->and($settings->longitude)->toBe(-5.6295678)
        ->and($settings->map_url)->toStartWith('https://www.google.com/maps/place/Baobab')
        ->and(substr((string) $settings->check_in_time, 0, 5))->toBe('14:00')
        ->and($settings->address_line1)->toBeNull() // not ticked
        ->and($suite->localizedName('fr', 'fr'))->toBe('Suite Baobab')
        ->and(($this->in)(fn () => Room::query()->where('room_category_id', $suite->id)->count()))->toBe(2)
        ->and(($this->in)(fn () => CategoryRate::query()->where('room_category_id', $suite->id)->value('base_minor')))->toBe(45000)
        ->and(($this->in)(fn () => KnowledgeEntry::query()->where('section', 'restaurant')->count()))->toBe(2)
        ->and(($this->listing)())->toBeNull()
        ->and(AuditLog::query()->where('event', 'onboarding.listing_applied')->exists())->toBeTrue();
});

it('expands short Maps links, and notes each source that could not be read', function (): void {
    Http::fake([
        'maps.app.goo.gl/*' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/place/Lodge/@9.4,-5.6,17z/data=!3d9.4!4d-5.6']),
        'blocked.ci/robots.txt' => Http::response("User-agent: *\nDisallow: /", 200, ['Content-Type' => 'text/plain']),
        '*' => Http::response('should not be read', 200),
    ]);
    ListingAgent::fake([listingReply(['rooms' => [], 'entries' => []])]);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), [
        'website_url' => 'https://blocked.ci/chambres',
        'maps_url' => 'https://maps.app.goo.gl/AbC123',
    ])->assertSessionHasNoErrors();

    Http::assertNotSent(fn (Request $r): bool => $r->url() === 'https://blocked.ci/chambres');
    $listing = ($this->listing)();
    expect($listing['status'])->toBe('ready')
        ->and($listing['profile']->location['value'])->toEqual(['latitude' => 9.4, 'longitude' => -5.6])
        ->and($listing['notes'][0])->toContain('demande aux robots de ne pas lire');
});

it('never reads addresses on the server\'s own network', function (): void {
    Http::fake();
    ListingAgent::fake([listingReply()]);
    $this->resolved = ['10.0.0.5'];

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['website_url' => 'https://intranet.example/'])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['website_url' => 'http://169.254.169.254/latest/meta-data'])->assertSessionHasNoErrors();

    Http::assertNothingSent();
    $listing = ($this->listing)();
    expect($listing['status'])->toBe('failed')
        ->and($listing['error'])->toContain('ne peut pas être lu');
});

it('reads photos of a rate card, and falls back to the text when photos cannot be read', function (): void {
    $calls = 0;
    ListingAgent::fake(function (string $prompt) use (&$calls): string {
        if (++$calls <= 2) { // the main model, then the backup
            throw new RuntimeException('This model cannot read images.');
        }

        return listingReply();
    });

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), [
        'pasted_text' => 'Chambre Standard 25 000 FCFA',
        'files' => [UploadedFile::fake()->image('tarifs.jpg', 800, 600)],
    ])->assertSessionHasNoErrors();

    $listing = ($this->listing)();
    $import = ($this->in)(fn () => ListingImport::query()->sole());
    expect($listing['status'])->toBe('ready')
        ->and($listing['notes'][0])->toContain('Photos et PDF')
        ->and($import->files)->toHaveCount(1)
        ->and(Storage::disk('local')->exists($import->files[0]['path']))->toBeTrue();

    // Photos alone, and the AI is down: nothing to show, a clear message, and the owner can start again.
    ListingAgent::fake(fn () => throw new RuntimeException('down'));
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['files' => [UploadedFile::fake()->create('brochure.pdf', 200, 'application/pdf')]]);
    expect(($this->listing)()['status'])->toBe('failed')
        ->and(Storage::disk('local')->exists($import->files[0]['path']))->toBeFalse(); // the replaced import's photo is gone
});

it('checks what the owner gives, and limits imports per day', function (): void {
    ListingAgent::fake(fn () => listingReply());

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), [])->assertSessionHasErrors('website_url');
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['maps_url' => 'https://example.com/maps'])->assertSessionHasErrors('maps_url');
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['website_url' => 'ftp://baobab.ci'])->assertSessionHasErrors('website_url');
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['pasted_text' => 'x', 'files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('files.0');

    ($this->in)(function (): void {
        for ($i = 0; $i < ListingImport::DAILY_LIMIT; $i++) {
            ListingImport::query()->create(['status' => 'discarded', 'pasted_text' => 'x']);
        }
    });
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['pasted_text' => 'Hôtel calme'])->assertSessionHasErrors('website_url');

    $this->travel(25)->hours();
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['pasted_text' => 'Hôtel calme'])->assertSessionHasNoErrors();
});

it('is for owners and managers only, and keeps each hotel\'s imports to itself', function (): void {
    ListingAgent::fake(fn () => listingReply());
    $reception = staffMember($this->hotel, SystemRole::Reception);
    asStaffOf($this->hotel, $reception)->post(route('hotel.setup.listing'), ['pasted_text' => 'x'])->assertForbidden();

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['pasted_text' => 'Hôtel calme']);
    $import = ($this->in)(fn () => ListingImport::query()->sole());

    $other = Hotel::factory()->create();
    $otherOwner = staffMember($other, SystemRole::Owner);
    asStaffOf($other, $otherOwner)->post(route('hotel.setup.listing.apply', $import->id), ['profile' => ['check_out_time']])->assertNotFound();
    asStaffOf($other, $otherOwner)->post(route('hotel.setup.listing.discard', $import->id))->assertNotFound();

    // Nothing ticked is refused; a used import cannot be used twice.
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing.apply', $import->id), [])->assertSessionHasErrors('listing');
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing.apply', $import->id), ['profile' => ['check_out_time']])->assertSessionHasNoErrors();
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing.apply', $import->id), ['profile' => ['check_out_time']])->assertSessionHasErrors('listing');
});

it('lets the owner discard an import, and removes old imports with their photos after 7 days', function (): void {
    ListingAgent::fake(fn () => listingReply());
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['pasted_text' => 'x', 'files' => [UploadedFile::fake()->image('a.png')]]);
    $import = ($this->in)(fn () => ListingImport::query()->sole());

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing.discard', $import->id))->assertRedirect();
    expect(($this->listing)())->toBeNull()
        ->and(Storage::disk('local')->exists($import->files[0]['path']))->toBeFalse();

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.listing'), ['pasted_text' => 'y', 'files' => [UploadedFile::fake()->image('b.png')]]);
    $kept = ($this->in)(fn () => ListingImport::query()->where('status', 'ready')->sole());
    $this->travel(8)->days();
    $this->artisan('model:prune', ['--model' => [ListingImport::class]])->assertSuccessful();

    expect(ListingImport::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($kept->files[0]['path']))->toBeFalse();
});

it('gives staging a realistic reading without an AI provider', function (): void {
    config(['simulation.ai.latency_ms' => [0, 0], 'simulation.ai.failure_rate' => 0]);
    $draft = ListingDraft::build(SimulatedAi::listing("[Google Maps]\nPlace name: First Class Lodge"), [], null);

    expect($draft['profile']['name']['value'])->toBe('First Class Lodge')
        ->and($draft['rooms'])->toHaveCount(3)
        ->and(collect($draft['entries'])->pluck('locale')->unique()->sort()->values()->all())->toBe(['en', 'fr']);
});

it('sets the map pin when the owner saves a Google Maps link in the settings', function (): void {
    $settings = ($this->in)(fn () => HotelSettings::current());
    $payload = [
        'name' => $this->hotel->name, 'timezone' => 'Africa/Abidjan', 'currency' => 'XOF', 'default_locale' => 'fr', 'enabled_locales' => ['fr'],
        'check_in_time' => '14:00', 'check_out_time' => '12:00', 'tentative_hours' => $settings->tentative_hours,
        'map_url' => 'https://www.google.com/maps/place/Lodge/@9.45,-5.63,17z',
    ];

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.settings.update'), $payload)->assertSessionHasNoErrors();
    expect(($this->in)(fn () => HotelSettings::current()->latitude))->toBe(9.45);

    asStaffOf($this->hotel, $this->owner)->put(route('hotel.settings.update'), [...$payload, 'map_url' => null])->assertSessionHasNoErrors();
    expect(($this->in)(fn () => HotelSettings::current()->latitude))->toBeNull();
});
