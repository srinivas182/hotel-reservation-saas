<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Site\Models\BrandSettings;

/**
 * Fills the legal texts' {{company}}, {{address}}, {{email}} and {{brand}}
 * from the issuing company (Platform → Billing) and the brand settings
 * (0.37.7), so the texts stay right when those details change.
 */
final class LegalPlaceholders
{
    public static function fill(string $text, string $locale): string
    {
        $brand = BrandSettings::cached();
        $entity = BillingEntity::default();
        $name = (string) ($brand['name'] ?? config('app.name'));

        $email = $brand['support_email'] ?? $entity?->email;

        return strtr($text, [
            '{{company}}' => trim((string) ($entity->legal_name ?? '')) !== '' ? (string) $entity?->legal_name : $name,
            '{{address}}' => trim((string) ($entity->address ?? '')) !== '' ? preg_replace('/\s*\n\s*/', ', ', trim((string) $entity?->address)) : "Abidjan, Côte d'Ivoire",
            '{{email}}' => is_string($email) && $email !== '' ? '['.$email.'](mailto:'.$email.')' : ($locale === 'fr' ? 'l’adresse indiquée sur notre site' : 'the address shown on our website'),
            '{{brand}}' => $name,
        ]);
    }
}
