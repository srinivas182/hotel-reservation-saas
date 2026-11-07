<?php

declare(strict_types=1);

use App\Domains\Access\SystemRole;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Onboarding\SetupChecklist;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;

require_once __DIR__.'/../Access/helpers.php';
require_once __DIR__.'/../Rooms/helpers.php';
require_once __DIR__.'/../Rates/helpers.php';

beforeEach(function (): void {
    $this->travelTo('2026-11-13 10:00:00');
    $this->hotel = Hotel::factory()->create(['currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'country_code' => 'CI', 'default_locale' => 'fr']);
    $this->in = fn (callable $callback) => app(TenantContext::class)->run($this->hotel, $callback);
    $this->owner = staffMember($this->hotel, SystemRole::Owner);
    $this->steps = fn (): array => collect(($this->in)(fn () => app(SetupChecklist::class)->steps()))->keyBy('key')->all();
    $this->csv = fn (string $content) => UploadedFile::fake()->createWithContent('import.csv', $content);
});

it('works each step out from the hotel\'s real data, and lets steps be skipped', function (): void {
    expect(collect(($this->steps)())->where('done', true)->count())->toBe(0);

    $category = roomCategory($this->hotel, 'STD', 2);
    rateFor($this->hotel, $category);
    ($this->in)(fn () => HotelSettings::current()->update(['address_line1' => 'Quartier Commerce', 'phone_e164' => '+2250700000001', 'staffed_hours' => ['mon' => [['from' => '07:00', 'to' => '22:00']]]]));
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.taxes'))->assertRedirect();
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.skip', 'team'), ['skip' => true]);

    $steps = ($this->steps)();
    expect($steps['details']['done'])->toBeTrue()
        ->and($steps['rooms']['done'])->toBeTrue()
        ->and($steps['prices']['done'])->toBeTrue()
        ->and($steps['taxes']['done'])->toBeTrue()
        ->and($steps['hours']['done'])->toBeTrue()
        ->and($steps['team']['skipped'])->toBeTrue()
        ->and($steps['whatsapp']['done'])->toBeFalse()
        ->and(AuditLog::query()->where('event', 'setup.taxes_confirmed')->exists())->toBeTrue();

    asStaffOf($this->hotel, $this->owner)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->where('setup.done', 6)->where('setup.total', 9));
    foreach (['whatsapp', 'knowledge', 'practice'] as $step) {
        asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.skip', $step), ['skip' => true]);
    }
    asStaffOf($this->hotel, $this->owner)->get(route('hotel.today'))->assertInertia(fn ($page) => $page->where('setup', null));

    asStaffOf($this->hotel, staffMember($this->hotel, SystemRole::Reception))->get(route('hotel.setup'))->assertForbidden();
});

it('creates rooms from categories and counts, numbering around rooms that exist', function (): void {
    $existing = roomCategory($this->hotel, 'OLD', 0);
    ($this->in)(fn () => Room::query()->create(['room_category_id' => $existing->id, 'number' => '103', 'status' => 'in_service']));

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.rooms'), [
        'categories' => [['name' => 'Standard', 'count' => 3, 'adults' => 2], ['name' => 'Suite', 'count' => 2, 'adults' => 3], ['name' => 'Standing', 'count' => 1, 'adults' => 2]],
        'first_number' => 101,
    ])->assertSessionHasNoErrors();

    $rooms = ($this->in)(fn () => Room::query()->with('category')->orderBy('number')->get());
    expect($rooms->pluck('number')->all())->toBe(['101', '102', '103', '104', '105', '106', '107'])
        ->and(($this->in)(fn () => RoomCategory::query()->pluck('code')->sort()->values()->all()))->toBe(['OLD', 'STA', 'STA2', 'SUI'])
        ->and(($this->in)(fn () => RoomCategory::query()->where('code', 'SUI')->sole()->max_adults))->toBe(3);
});

it('imports rooms from a file after a preview, skipping lines with problems', function (): void {
    roomCategory($this->hotel, 'STD', 0);
    roomCategory($this->hotel, 'SUI', 0, ['name' => ['fr' => 'Suite', 'en' => 'Suite']]);
    $csv = "\xEF\xBB\xBFcategory;number;floor\nSTD;101;1\nsuite;201;2\nVIP;301;3\nSTD;101;1\n";

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.import', 'rooms'), ['file' => ($this->csv)($csv)])
        ->assertSessionHas('inertia.flash_data', function (array $flash): bool {
            $rows = collect($flash['import']['rows']);
            $this->token = $flash['import']['token'];

            return $rows->where('errors', [])->count() === 2 && str_contains($rows[2]['errors'][0], 'VIP') && $rows[3]['errors'] !== [];
        });
    expect(($this->in)(fn () => Room::query()->count()))->toBe(0);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.import.confirm'), ['token' => $this->token])->assertSessionHasNoErrors();
    expect(($this->in)(fn () => Room::query()->orderBy('number')->pluck('number')->all()))->toBe(['101', '201']);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.import.confirm'), ['token' => $this->token])->assertSessionHasErrors('file');
    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.import', 'rooms'), ['file' => ($this->csv)("number\n101\n")])->assertSessionHasErrors('file');
});

it('imports upcoming bookings through the normal booking rules', function (): void {
    $category = roomCategory($this->hotel, 'STD', 1);
    rateFor($this->hotel, $category);
    $csv = implode("\n", [
        'first_name,last_name,phone,email,check_in,check_out,category,rooms,adults,total,reference',
        'Awa,Koné,+2250700000001,,20/11/2026,22/11/2026,STD,1,2,"45 000",ANC-1',
        'Kofi,Mensah,,,2026-11-21,2026-11-23,STD,1,1,,ANC-2',
        'Old,Stay,,,2026-11-01,2026-11-03,STD,1,1,,',
        'Bad,Dates,,,2026-11-25,2026-11-24,STD,1,1,,',
        'No,Category,,,2026-11-25,2026-11-26,XXX,1,1,,',
    ]);

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.import', 'bookings'), ['file' => ($this->csv)($csv)])
        ->assertSessionHas('inertia.flash_data', function (array $flash): bool {
            $this->token = $flash['import']['token'];

            return collect($flash['import']['rows'])->where('errors', [])->count() === 2;
        });

    asStaffOf($this->hotel, $this->owner)->post(route('hotel.setup.import.confirm'), ['token' => $this->token])
        ->assertSessionHas('inertia.flash_data', fn (array $flash): bool => $flash['import_result']['saved'] === 1 && $flash['import_result']['refused'][0]['line'] === 3);

    $booking = ($this->in)(fn () => Booking::query()->with('guest')->sole());
    expect($booking->check_in->toDateString())->toBe('2026-11-20')
        ->and($booking->total_minor)->toBe(45000)
        ->and($booking->external_reference)->toBe('ANC-1')
        ->and($booking->guest?->phone_e164)->toBe('+2250700000001');
});
