<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Knowledge;

/**
 * The sections of the information pack, in the order the assistant reads
 * them. 0.37.0 adds the questions guests often ask before booking: rooms
 * and facilities, children, pets and accessibility.
 */
enum KnowledgeSection: string
{
    case About = 'about';
    case Location = 'location';
    case Amenities = 'amenities';
    case Policies = 'policies';
    case Children = 'children';
    case Pets = 'pets';
    case Accessibility = 'accessibility';
    case Payments = 'payments';
    case Parking = 'parking';
    case Transfers = 'transfers';
    case Restaurant = 'restaurant';
    case HouseRules = 'house_rules';
    case Faq = 'faq';

    public function label(): string
    {
        return match ($this) {
            self::About => __('About the hotel'),
            self::Location => __('Location and directions'),
            self::Amenities => __('Rooms, Wi-Fi and facilities'),
            self::Policies => __('Booking, cancellation and no-show policy'),
            self::Children => __('Children, cots and extra beds'),
            self::Pets => __('Pets'),
            self::Accessibility => __('Accessibility'),
            self::Payments => __('Payment methods and deposits'),
            self::Parking => __('Parking'),
            self::Transfers => __('Airport and bus transfers'),
            self::Restaurant => __('Restaurant and bar'),
            self::HouseRules => __('House rules'),
            self::Faq => __('Questions and answers'),
        };
    }

    /**
     * What to write in this section: shown in the empty text box, so the
     * hotel covers what guests actually ask.
     */
    public function hint(): string
    {
        return match ($this) {
            self::About => __('Style of the hotel, number of rooms, what guests like most.'),
            self::Location => __('District, landmarks, how to come from the airport or bus station.'),
            self::Amenities => __('Wi-Fi, air conditioning, generator or solar power, hot water, TV, safe, pool, gym, laundry, room cleaning times.'),
            self::Policies => __('Check-in and check-out times, early arrival and late departure, cancellation, no-show, ID asked at arrival.'),
            self::Children => __('Ages that stay free, baby cot (free or price), extra bed, babysitting.'),
            self::Pets => __('Allowed or not, which animals and sizes, fee per night, deposit, places they cannot go, assistance dogs.'),
            self::Accessibility => __('Step-free entrance, lift, ground-floor or adapted rooms, parking close to the door.'),
            self::Payments => __('Cash, mobile money, cards, bank transfer, deposit asked to confirm.'),
            self::Parking => __('Available or not, free or price, guarded, size of vehicles.'),
            self::Transfers => __('Airport or bus station pick-up: offered, price, how to book.'),
            self::Restaurant => __('Breakfast times and price, restaurant and bar hours, type of food.'),
            self::HouseRules => __('Smoking, visitors, quiet hours, pool hours, dress code.'),
            self::Faq => __('Any question guests often ask, with the answer.'),
        };
    }
}
