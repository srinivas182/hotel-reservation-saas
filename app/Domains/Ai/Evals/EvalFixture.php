<?php

declare(strict_types=1);

namespace App\Domains\Ai\Evals;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Knowledge\KnowledgeEntry;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Rates\BreakfastMode;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Rooms\RoomStatus;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * A complete test hotel for the assistant test suite. Created inside a
 * transaction that the runner rolls back, so it never stays in the database.
 */
final class EvalFixture
{
    public function create(): Hotel
    {
        $hotel = new Hotel;
        $hotel->forceFill([
            'name' => 'Hôtel Évaluation',
            'slug' => 'eval-'.Str::lower(Str::random(8)),
            'country_code' => 'CI',
            'timezone' => 'Africa/Abidjan',
            'currency' => 'XOF',
            'default_locale' => 'fr',
            'status' => HotelStatus::Active,
        ])->save();

        app(TenantContext::class)->run($hotel, function (): void {
            HotelSettings::current()->update([
                'enabled_locales' => ['fr', 'en'],
                'address_line1' => 'Avenue de la République',
                'city' => 'Korhogo',
                'phone_e164' => '+2250700000000',
                'check_in_time' => '14:00:00',
                'check_out_time' => '12:00:00',
                'late_checkout_until' => '15:00:00',
                'child_age_bands' => [['from' => 0, 'to' => 5, 'charge' => 'free'], ['from' => 6, 'to' => 11, 'charge' => 'child']],
            ]);

            TaxRate::query()->create(['name' => ['fr' => 'Taxe de séjour', 'en' => 'Tourist tax'], 'type' => 'fixed_per_person_per_night', 'amount_minor' => 1000, 'inclusive' => false, 'applies_to' => ['rooms'], 'sort_order' => 1]);

            foreach ([
                ['STD', 'Standard', 'Standard', 10, 2, 1, 3, 20000],
                ['EXE', 'Exécutive', 'Executive', 10, 2, 1, 3, 25000],
                ['SUI', 'Suite', 'Suite', 3, 3, 2, 4, 40000],
            ] as $order => [$code, $fr, $en, $rooms, $adults, $children, $max, $price]) {
                $category = RoomCategory::query()->create([
                    'code' => $code, 'name' => ['fr' => $fr, 'en' => $en], 'standard_occupancy' => 2,
                    'max_adults' => $adults, 'max_children' => $children, 'max_occupancy' => $max,
                    'extra_bed_allowed' => $code === 'SUI', 'amenities' => ['air_conditioning', 'wifi', 'tv'], 'active' => true, 'sort_order' => $order,
                ]);

                CategoryRate::query()->create([
                    'room_category_id' => $category->id, 'base_minor' => $price, 'extra_adult_minor' => 5000, 'child_minor' => 2500,
                    'extra_bed_minor' => 5000, 'breakfast_mode' => BreakfastMode::Optional, 'breakfast_minor' => 3000, 'min_nights' => 1,
                ]);

                foreach (range(1, $rooms) as $i) {
                    Room::query()->create(['room_category_id' => $category->id, 'number' => $code.$i, 'status' => RoomStatus::InService]);
                }
            }

            foreach ([
                'fr' => [
                    ['payments', null, 'Paiement en espèces, Orange Money, MTN Mobile Money ou Wave. Pas de carte bancaire. Un acompte de 30 % confirme la réservation.'],
                    ['policies', null, 'Annulation gratuite jusqu\'à 48 heures avant l\'arrivée. En cas de non-présentation, l\'acompte est conservé.'],
                    ['parking', null, 'Parking gratuit et gardé dans la cour de l\'hôtel.'],
                    ['restaurant', null, 'Restaurant ouvert de 7 h à 22 h, bar jusqu\'à minuit.'],
                    ['location', null, 'Près du grand marché, à 10 minutes de la gare routière en taxi.'],
                    ['faq', 'Avez-vous le wifi ?', 'Oui, wifi gratuit dans tout l\'hôtel.'],
                ],
                'en' => [
                    ['payments', null, 'Cash, Orange Money, MTN Mobile Money or Wave. No card payments. A 30% deposit confirms the booking.'],
                    ['policies', null, 'Free cancellation up to 48 hours before arrival. For no-shows the deposit is kept.'],
                    ['parking', null, 'Free guarded parking in the hotel courtyard.'],
                    ['restaurant', null, 'Restaurant open 7am to 10pm, bar until midnight.'],
                    ['location', null, 'Near the main market, 10 minutes by taxi from the bus station.'],
                    ['faq', 'Do you have wifi?', 'Yes, free wifi everywhere in the hotel.'],
                ],
            ] as $locale => $entries) {
                foreach ($entries as $i => [$section, $question, $content]) {
                    KnowledgeEntry::query()->create(['locale' => $locale, 'section' => $section, 'question' => $question, 'content' => $content, 'sort_order' => $i]);
                }
            }

            app(KnowledgePack::class)->publish();

            HotelAssistantSettings::current()->update([
                'languages' => ['fr', 'en'],
                'daily_budget_micro_usd' => 1_000_000_000,
                'kill_switch' => false,
            ]);
        });

        return $hotel;
    }
}
