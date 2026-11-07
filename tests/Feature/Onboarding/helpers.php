<?php

declare(strict_types=1);

use App\Domains\Onboarding\LegalDefaults;
use App\Domains\Onboarding\Models\LegalDocument;
use App\Domains\Onboarding\Models\SignupSettings;

/**
 * TestCase removes legal texts; these tests publish the defaults again.
 */
function publishLegalDefaults(): void
{
    foreach (LegalDefaults::all() as $kind => $body) {
        LegalDocument::query()->create(['kind' => $kind, 'version' => 1, 'body' => $body, 'published_at' => now()]);
    }
}

function signupMode(string $mode): void
{
    SignupSettings::current()->update(['mode' => $mode]);
}

/**
 * @return array<string, mixed>
 */
function signupPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Aïcha', 'last_name' => 'Traoré', 'email' => 'aicha@baobab.example', 'phone' => '+225 07 08 09 10 11',
        'password' => 'Un-mot-de-passe-solide-2026', 'password_confirmation' => 'Un-mot-de-passe-solide-2026',
        'hotel_name' => 'Hôtel Le Baobab', 'country_code' => 'CI', 'city' => 'Bouaké', 'rooms' => 18,
        'accept_terms' => true, 'accept_dpa' => true, ...$overrides,
    ];
}
