<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

/**
 * The 15 questions the information pack is drafted from.
 */
final class Questionnaire
{
    /**
     * @return array<string, string> key => question
     */
    public static function questions(): array
    {
        return [
            'about' => (string) __('Describe your hotel in two or three sentences (style, what guests like).'),
            'location' => (string) __('Where is the hotel? (district, landmarks)'),
            'directions' => (string) __('How do guests get there from the bus station or airport?'),
            'checkin' => (string) __('Check-in and check-out times, early arrival or late departure?'),
            'breakfast' => (string) __('Breakfast: included or not, times, price?'),
            'restaurant' => (string) __('Restaurant or bar: opening hours, type of food?'),
            'payment' => (string) __('How can guests pay? (cash, Orange Money, Wave, card…)'),
            'cancellation' => (string) __('Your cancellation policy?'),
            'deposit' => (string) __('Do you ask for a deposit? How much and how?'),
            'children' => (string) __('Children: ages, extra beds, prices?'),
            'pets' => (string) __('Are pets allowed?'),
            'parking' => (string) __('Parking: available, free, secured?'),
            'transfers' => (string) __('Airport or station transfers: offered, price?'),
            'wifi' => (string) __('Wi-Fi, air conditioning, generator, other facilities?'),
            'rules' => (string) __('House rules (smoking, visitors, quiet hours…)?'),
        ];
    }

    /**
     * Which section of the information pack each answer feeds.
     *
     * @return array<string, string>
     */
    public static function sections(): array
    {
        return [
            'about' => 'about', 'location' => 'location', 'directions' => 'location', 'checkin' => 'policies',
            'breakfast' => 'restaurant', 'restaurant' => 'restaurant', 'payment' => 'payments', 'cancellation' => 'policies',
            'deposit' => 'payments', 'children' => 'policies', 'pets' => 'house_rules', 'parking' => 'parking',
            'transfers' => 'transfers', 'wifi' => 'about', 'rules' => 'house_rules',
        ];
    }
}
