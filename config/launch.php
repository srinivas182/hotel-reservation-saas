<?php

declare(strict_types=1);

/*
| Launch defaults (S17.3d). Industry-standard starting values, all
| changeable later in the platform settings. See docs/launch-defaults.md.
*/
return [
    // Invoices are never deleted (not even when a hotel closes); OHADA
    // accounting rules ask for 10 years. Shown in Billing and the docs.
    'invoice_retention_years' => (int) env('INVOICE_RETENTION_YEARS', 10),

    // First answer to a support request, in hours, per plan code.
    'support_response_hours' => ['default' => 24, 'essentiel' => 24, 'pro' => 8, 'complet' => 4],

    'support_hours' => [
        'fr' => 'Du lundi au samedi, de 8 h à 18 h (heure d’Abidjan, GMT), en français et en anglais.',
        'en' => 'Monday to Saturday, 8 am to 6 pm (Abidjan time, GMT), in French and English.',
    ],

    // Prices in more West and Central African currencies, by plan code:
    // [monthly, yearly] in the currency's units (two months free a year).
    'extra_prices' => [
        'essentiel' => ['XAF' => [25000, 250000], 'GHS' => [650, 6500], 'NGN' => [65000, 650000]],
        'pro' => ['XAF' => [45000, 450000], 'GHS' => [1150, 11500], 'NGN' => [115000, 1150000]],
        'complet' => ['XAF' => [75000, 750000], 'GHS' => [1950, 19500], 'NGN' => [195000, 1950000]],
    ],

    // Minor units per currency for the prices above (FCFA have none).
    'minor_units' => ['XOF' => 1, 'XAF' => 1, 'GHS' => 100, 'NGN' => 100],
];
