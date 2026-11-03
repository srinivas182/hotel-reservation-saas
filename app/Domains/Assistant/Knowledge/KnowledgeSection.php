<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Knowledge;

enum KnowledgeSection: string
{
    case About = 'about';
    case Location = 'location';
    case Policies = 'policies';
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
            self::Policies => __('Booking, cancellation and no-show policy'),
            self::Payments => __('Payment methods and deposits'),
            self::Parking => __('Parking'),
            self::Transfers => __('Airport and bus transfers'),
            self::Restaurant => __('Restaurant and bar'),
            self::HouseRules => __('House rules'),
            self::Faq => __('Questions and answers'),
        };
    }
}
