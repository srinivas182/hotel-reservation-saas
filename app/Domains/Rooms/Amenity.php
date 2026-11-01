<?php

declare(strict_types=1);

namespace App\Domains\Rooms;

/**
 * Room features a hotel can tick. A fixed list keeps them translatable and
 * lets the WhatsApp assistant (S5) describe rooms consistently.
 */
enum Amenity: string
{
    case AirConditioning = 'air_conditioning';
    case Fan = 'fan';
    case Wifi = 'wifi';
    case Tv = 'tv';
    case Minibar = 'minibar';
    case Fridge = 'fridge';
    case Kettle = 'kettle';
    case Desk = 'desk';
    case Safe = 'safe';
    case Balcony = 'balcony';
    case PrivateBathroom = 'private_bathroom';
    case HotWater = 'hot_water';
    case Bathtub = 'bathtub';
    case SittingArea = 'sitting_area';

    public function label(): string
    {
        return match ($this) {
            self::AirConditioning => __('Air conditioning'),
            self::Fan => __('Fan'),
            self::Wifi => __('Wi-Fi'),
            self::Tv => __('TV'),
            self::Minibar => __('Minibar'),
            self::Fridge => __('Fridge'),
            self::Kettle => __('Kettle'),
            self::Desk => __('Desk'),
            self::Safe => __('Safe'),
            self::Balcony => __('Balcony'),
            self::PrivateBathroom => __('Private bathroom'),
            self::HotWater => __('Hot water'),
            self::Bathtub => __('Bathtub'),
            self::SittingArea => __('Sitting area'),
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $amenity): string => $amenity->value, self::cases());
    }
}
