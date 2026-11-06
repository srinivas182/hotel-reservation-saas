<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Menu;

/**
 * Dish labels and allergen labels. Allergens are information about the
 * dish, chosen by the hotel; they say nothing about any guest.
 */
final class MenuLabel
{
    public const LABELS = ['vegetarian', 'vegan', 'spicy', 'halal', 'house_special'];

    public const ALLERGENS = ['gluten', 'crustaceans', 'eggs', 'fish', 'peanuts', 'soy', 'milk', 'nuts', 'celery', 'mustard', 'sesame', 'sulphites', 'lupin', 'molluscs'];

    public static function label(string $key): string
    {
        return match ($key) {
            'vegetarian' => __('Vegetarian'),
            'vegan' => __('Vegan'),
            'spicy' => __('Spicy'),
            'halal' => __('Halal'),
            'house_special' => __('House special'),
            'gluten' => __('Gluten'),
            'crustaceans' => __('Crustaceans'),
            'eggs' => __('Eggs'),
            'fish' => __('Fish'),
            'peanuts' => __('Peanuts'),
            'soy' => __('Soy'),
            'milk' => __('Milk'),
            'nuts' => __('Tree nuts'),
            'celery' => __('Celery'),
            'mustard' => __('Mustard'),
            'sesame' => __('Sesame'),
            'sulphites' => __('Sulphites'),
            'lupin' => __('Lupin'),
            'molluscs' => __('Molluscs'),
            default => $key,
        };
    }
}
