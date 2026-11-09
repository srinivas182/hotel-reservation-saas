<?php

declare(strict_types=1);

namespace App\Domains\Demo;

use App\Domains\Access\HotelRoles;
use App\Domains\Access\SystemRole;
use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Audit\Audit;
use App\Domains\Bookings\BookingService;
use App\Domains\Events\Models\EventSpace;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Platform\HotelAdministration;
use App\Domains\Platform\Lifecycle\HotelTables;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Restaurant\Menu\MenuItem;
use App\Domains\Restaurant\Menu\MenuSection;
use App\Domains\Restaurant\Models\Outlet;
use App\Domains\Restaurant\Models\ServicePeriod;
use App\Domains\Restaurant\OutletType;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

/**
 * Demo hotels for sales: realistic rooms, prices, restaurant, events and an
 * information pack; the real assistant answers; WhatsApp is simulated
 * (nothing is sent) unless a real demo number is connected (D).
 */
final class DemoHotels
{
    /** Default categories: code, French name, English name, price per night, rooms. */
    public const CATEGORIES = [
        ['STD', 'Standard', 'Standard', 20000, 10],
        ['EXE', 'Exécutive', 'Executive', 25000, 8],
        ['MSU', 'Mini suite', 'Junior suite', 35000, 5],
        ['SUI', 'Suite', 'Suite', 40000, 3],
    ];

    public function __construct(private readonly TenantContext $context) {}

    public static function permanent(): ?Hotel
    {
        return Hotel::query()->where('is_demo', true)->whereNull('demo_expires_at')->orderBy('created_at')->first();
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int}>|null  $categories
     * @return array{hotel: Hotel, user: User, password: string}
     */
    public function create(string $name, ?CarbonImmutable $expiresAt = null, ?array $categories = null): array
    {
        $slug = 'demo-'.Str::lower(Str::random(8));
        $password = Str::password(16, symbols: false);

        $user = User::query()->forceCreate([
            'first_name' => 'Démo', 'last_name' => $expiresAt === null ? 'Ventes' : 'Prospect', 'email' => $slug.'@demo.invalid',
            'password' => Hash::make($password), 'email_verified_at' => now(), 'preferred_locale' => 'fr',
        ]);

        // No owner (owners need two-step login) and no subscription (no credit limits).
        $hotel = app(HotelAdministration::class)->create([
            'name' => $name, 'country_code' => 'CI', 'timezone' => 'Africa/Abidjan', 'currency' => 'XOF', 'default_locale' => 'fr',
            'status' => HotelStatus::Active->value, 'slug' => $slug,
        ]);
        $hotel->forceFill(['is_demo' => true, 'demo_expires_at' => $expiresAt, 'signup_source' => 'platform'])->save();
        DB::table('subscriptions')->where('hotel_id', $hotel->id)->delete();

        app(\App\Domains\Access\StaffService::class)->assign($hotel, $user, app(HotelRoles::class)->system($hotel, SystemRole::Manager));
        $legal = app(\App\Domains\Onboarding\Legal::class);
        $legal->accept($user, $legal->missing($user), null, $hotel->id);

        $this->fill($hotel, $categories ?? self::CATEGORIES);
        Audit::record('demo.created', 'Demo hotel created', subject: $hotel, properties: ['expires' => $expiresAt?->toIso8601String()]);

        return ['hotel' => $hotel, 'user' => $user, 'password' => $password];
    }

    /**
     * Back to the starting point: data emptied and rebuilt; a real WhatsApp
     * number (D) and the staff accounts are kept.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int}>|null  $categories
     */
    public function reset(Hotel $hotel, ?array $categories = null, ?string $name = null): void
    {
        abort_unless($hotel->is_demo, 403);
        $realAccount = $this->context->run($hotel, fn () => WhatsAppAccount::query()->where('phone_number_id', 'not like', 'DEMO-%')->first()?->getAttributes());

        foreach (HotelTables::deletionOrder() as [$table, $via]) {
            if (in_array($table, ['hotel_user', 'model_has_roles', 'roles', 'role_has_permissions', 'legal_acceptances'], true)) {
                continue;
            }
            $via === null
                ? DB::table($table)->where('hotel_id', $hotel->id)->delete()
                : DB::table($table)->whereIn($via['column'], DB::table($via['parent'])->where('hotel_id', $hotel->id)->select($via['parent_column']))->delete();
        }

        if ($name !== null) {
            $hotel->forceFill(['name' => $name])->save();
        }
        if ($realAccount !== null) {
            DB::table('whatsapp_accounts')->insert($realAccount);
        }

        // Settings were deleted with the rest: recreate them.
        $this->fill($hotel, $categories ?? self::CATEGORIES, $realAccount === null);
        Audit::record('demo.reset', 'Demo hotel reset', subject: $hotel);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: int, 4: int}>  $categories
     */
    private function fill(Hotel $hotel, array $categories, bool $simulatedWhatsApp = true): void
    {
        $this->context->run($hotel, function () use ($hotel, $categories, $simulatedWhatsApp): void {
            HotelSettings::current()->update([
                'city' => 'Abidjan', 'address_line1' => 'Boulevard de la Paix', 'phone_e164' => '+2250700000000',
                'check_in_time' => '14:00', 'check_out_time' => '12:00', 'enabled_locales' => ['fr', 'en'],
            ]);

            if ($simulatedWhatsApp) {
                WhatsAppAccount::query()->create([
                    'waba_id' => 'DEMO-'.$hotel->id, 'phone_number_id' => 'DEMO-'.$hotel->id, 'display_phone' => '+225 00 00 00 00',
                    'status' => AccountStatus::Connected, 'token' => 'demo',
                ]);
            }

            $number = 101;
            foreach ($categories as $i => [$code, $fr, $en, $price, $count]) {
                $category = RoomCategory::query()->create([
                    'code' => $code, 'name' => ['fr' => $fr, 'en' => $en], 'description' => ['fr' => "Chambre {$fr} climatisée, Wi-Fi, télévision.", 'en' => "Air-conditioned {$en} room, Wi-Fi, TV."],
                    'standard_occupancy' => 2, 'max_adults' => 2, 'max_children' => 1, 'max_occupancy' => 3, 'extra_bed_allowed' => true, 'active' => true, 'sort_order' => $i,
                ]);
                CategoryRate::query()->create(['room_category_id' => $category->id, 'base_minor' => $price, 'extra_adult_minor' => 5000, 'child_minor' => 0, 'extra_bed_minor' => 5000, 'breakfast_mode' => BreakfastMode::Included, 'breakfast_minor' => 0, 'min_nights' => 1]);
                for ($n = 0; $n < $count; $n++) {
                    Room::query()->create(['room_category_id' => $category->id, 'number' => (string) $number++, 'floor' => (string) intdiv($number, 100), 'status' => RoomStatus::InService]);
                }
            }

            foreach ([
                ['about', 'Hôtel moderne au calme, à 10 minutes du centre. Climatisation, Wi-Fi rapide, groupe électrogène.', 'Modern, quiet hotel 10 minutes from the centre. Air conditioning, fast Wi-Fi, generator.'],
                ['policies', 'Arrivée à partir de 14 h, départ jusqu’à 12 h. Annulation gratuite jusqu’à 48 h avant l’arrivée.', 'Check-in from 2 pm, check-out until noon. Free cancellation up to 48 hours before arrival.'],
                ['payments', 'Paiement en espèces, Orange Money, MTN, Wave ou carte bancaire.', 'Payment in cash, Orange Money, MTN, Wave or card.'],
                ['parking', 'Parking gratuit et gardé jour et nuit.', 'Free parking, guarded day and night.'],
                ['transfers', 'Navette aéroport sur demande : 10 000 FCFA.', 'Airport shuttle on request: 10,000 FCFA.'],
                ['restaurant', 'Petit-déjeuner inclus de 6 h 30 à 10 h. Restaurant ouvert de 12 h à 22 h 30.', 'Breakfast included from 6:30 to 10 am. Restaurant open noon to 10:30 pm.'],
            ] as $i => [$section, $fr, $en]) {
                KnowledgeEntry::query()->create(['locale' => 'fr', 'section' => $section, 'content' => $fr, 'sort_order' => $i]);
                KnowledgeEntry::query()->create(['locale' => 'en', 'section' => $section, 'content' => $en, 'sort_order' => $i]);
            }
            app(KnowledgePack::class)->publish();

            $outlet = Outlet::query()->create(['name' => ['fr' => 'Restaurant Le Baobab', 'en' => 'Le Baobab restaurant'], 'type' => OutletType::Restaurant, 'seats' => 40, 'public_slug' => 'restaurant', 'orders_enabled' => true, 'active' => true]);
            foreach (range(1, 7) as $day) {
                ServicePeriod::query()->create(['outlet_id' => $outlet->id, 'weekday' => $day, 'name' => ['fr' => 'Service', 'en' => 'Service'], 'starts_at' => '06:30', 'ends_at' => '23:00', 'slot_minutes' => 30, 'last_seating_minutes' => 60]);
            }
            $section = MenuSection::query()->create(['outlet_id' => $outlet->id, 'name' => ['fr' => 'À la carte', 'en' => 'À la carte'], 'sort_order' => 0]);
            foreach ([['Poulet braisé, attiéké', 'Braised chicken, attiéké', 6000], ['Poisson grillé, alloco', 'Grilled fish, plantain', 7000], ['Café', 'Coffee', 1000], ['Jus de bissap', 'Bissap juice', 1500]] as $i => [$fr, $en, $price]) {
                MenuItem::query()->create(['menu_section_id' => $section->id, 'name' => ['fr' => $fr, 'en' => $en], 'price_minor' => $price, 'active' => true, 'orderable' => true, 'sort_order' => $i]);
            }

            EventSpace::query()->create(['name' => ['fr' => 'Salle Kanaga', 'en' => 'Kanaga room'], 'capacities' => ['theatre' => 80, 'classroom' => 40, 'banquet' => 60], 'active' => true, 'sort_order' => 0]);

            HotelAssistantSettings::current()->forceFill(['mode' => AssistantMode::Automatic, 'readiness_override_at' => now(), 'readiness_override_reason' => 'Demo hotel'])->save();

            // A guest in the house (for the room-order scenario).
            try {
                $category = RoomCategory::query()->orderBy('sort_order')->firstOrFail();
                $today = \App\Domains\Tenancy\HotelClock::today($hotel);
                $booking = app(BookingService::class)->create(new \App\Domains\Bookings\NewBooking(
                    stay: new \App\Domains\Rates\Quote\StayRequest($category->id, $today, $today->addDays(2), adults: 2, rooms: 1),
                    guest: ['first_name' => 'Aïcha', 'last_name' => 'Traoré', 'phone' => DemoScenarios::IN_HOUSE_PHONE],
                    channel: \App\Domains\Bookings\BookingChannel::Phone,
                    status: \App\Domains\Bookings\BookingStatus::Confirmed,
                ));
                $room = Room::query()->where('room_category_id', $category->id)->orderBy('number')->firstOrFail();
                app(\App\Domains\Bookings\BookingLifecycle::class)->assignRoom($booking, 1, $room);
                app(\App\Domains\Bookings\BookingLifecycle::class)->checkIn($booking->fresh() ?? $booking);
            } catch (Throwable) {
                // The demo still works without the stay.
            }
        });
    }
}
