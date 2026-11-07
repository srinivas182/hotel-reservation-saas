<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

/**
 * Starting country templates (West Africa first, then other African
 * countries). Tax rates are the usual standard or hotel rates and must be
 * checked with a local accountant; the platform can change everything.
 */
final class CountryDefaults
{
    /**
     * @return list<array{country_code: string, name: array<string, string>, currency: string, timezone: string, locale: string, taxes: list<array{name: string, rate: float}>}>
     */
    public static function all(): array
    {
        $fr = fn (string $fr, string $en): array => ['fr' => $fr, 'en' => $en];
        $tva = fn (float $rate): array => [['name' => 'TVA', 'rate' => $rate]];
        $vat = fn (float $rate): array => [['name' => 'VAT', 'rate' => $rate]];

        return [
            // West Africa — UEMOA (FCFA)
            ['country_code' => 'CI', 'name' => $fr('Côte d\'Ivoire', 'Côte d\'Ivoire'), 'currency' => 'XOF', 'timezone' => 'Africa/Abidjan', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'SN', 'name' => $fr('Sénégal', 'Senegal'), 'currency' => 'XOF', 'timezone' => 'Africa/Dakar', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'BF', 'name' => $fr('Burkina Faso', 'Burkina Faso'), 'currency' => 'XOF', 'timezone' => 'Africa/Ouagadougou', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'ML', 'name' => $fr('Mali', 'Mali'), 'currency' => 'XOF', 'timezone' => 'Africa/Bamako', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'BJ', 'name' => $fr('Bénin', 'Benin'), 'currency' => 'XOF', 'timezone' => 'Africa/Porto-Novo', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'TG', 'name' => $fr('Togo', 'Togo'), 'currency' => 'XOF', 'timezone' => 'Africa/Lome', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'NE', 'name' => $fr('Niger', 'Niger'), 'currency' => 'XOF', 'timezone' => 'Africa/Niamey', 'locale' => 'fr', 'taxes' => $tva(19)],
            ['country_code' => 'GW', 'name' => $fr('Guinée-Bissau', 'Guinea-Bissau'), 'currency' => 'XOF', 'timezone' => 'Africa/Bissau', 'locale' => 'fr', 'taxes' => [['name' => 'IGV', 'rate' => 17]]],
            // West Africa — other currencies
            ['country_code' => 'GN', 'name' => $fr('Guinée', 'Guinea'), 'currency' => 'GNF', 'timezone' => 'Africa/Conakry', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'GH', 'name' => $fr('Ghana', 'Ghana'), 'currency' => 'GHS', 'timezone' => 'Africa/Accra', 'locale' => 'en', 'taxes' => [['name' => 'VAT', 'rate' => 15], ['name' => 'NHIL', 'rate' => 2.5], ['name' => 'GETFund levy', 'rate' => 2.5]]],
            ['country_code' => 'NG', 'name' => $fr('Nigéria', 'Nigeria'), 'currency' => 'NGN', 'timezone' => 'Africa/Lagos', 'locale' => 'en', 'taxes' => $vat(7.5)],
            ['country_code' => 'SL', 'name' => $fr('Sierra Leone', 'Sierra Leone'), 'currency' => 'SLE', 'timezone' => 'Africa/Freetown', 'locale' => 'en', 'taxes' => [['name' => 'GST', 'rate' => 15]]],
            ['country_code' => 'GM', 'name' => $fr('Gambie', 'The Gambia'), 'currency' => 'GMD', 'timezone' => 'Africa/Banjul', 'locale' => 'en', 'taxes' => $vat(15)],
            ['country_code' => 'LR', 'name' => $fr('Libéria', 'Liberia'), 'currency' => 'LRD', 'timezone' => 'Africa/Monrovia', 'locale' => 'en', 'taxes' => [['name' => 'GST', 'rate' => 10]]],
            ['country_code' => 'MR', 'name' => $fr('Mauritanie', 'Mauritania'), 'currency' => 'MRU', 'timezone' => 'Africa/Nouakchott', 'locale' => 'fr', 'taxes' => $tva(16)],
            ['country_code' => 'CV', 'name' => $fr('Cap-Vert', 'Cabo Verde'), 'currency' => 'CVE', 'timezone' => 'Atlantic/Cape_Verde', 'locale' => 'en', 'taxes' => [['name' => 'IVA', 'rate' => 15]]],
            // Central Africa — CEMAC (FCFA) and others
            ['country_code' => 'CM', 'name' => $fr('Cameroun', 'Cameroon'), 'currency' => 'XAF', 'timezone' => 'Africa/Douala', 'locale' => 'fr', 'taxes' => $tva(19.25)],
            ['country_code' => 'GA', 'name' => $fr('Gabon', 'Gabon'), 'currency' => 'XAF', 'timezone' => 'Africa/Libreville', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'CG', 'name' => $fr('Congo', 'Republic of the Congo'), 'currency' => 'XAF', 'timezone' => 'Africa/Brazzaville', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'TD', 'name' => $fr('Tchad', 'Chad'), 'currency' => 'XAF', 'timezone' => 'Africa/Ndjamena', 'locale' => 'fr', 'taxes' => $tva(18)],
            ['country_code' => 'CF', 'name' => $fr('Centrafrique', 'Central African Republic'), 'currency' => 'XAF', 'timezone' => 'Africa/Bangui', 'locale' => 'fr', 'taxes' => $tva(19)],
            ['country_code' => 'GQ', 'name' => $fr('Guinée équatoriale', 'Equatorial Guinea'), 'currency' => 'XAF', 'timezone' => 'Africa/Malabo', 'locale' => 'fr', 'taxes' => [['name' => 'IVA', 'rate' => 15]]],
            ['country_code' => 'CD', 'name' => $fr('RD Congo', 'DR Congo'), 'currency' => 'CDF', 'timezone' => 'Africa/Kinshasa', 'locale' => 'fr', 'taxes' => $tva(16)],
            // North, East and Southern Africa
            ['country_code' => 'MA', 'name' => $fr('Maroc', 'Morocco'), 'currency' => 'MAD', 'timezone' => 'Africa/Casablanca', 'locale' => 'fr', 'taxes' => $tva(10)],
            ['country_code' => 'TN', 'name' => $fr('Tunisie', 'Tunisia'), 'currency' => 'TND', 'timezone' => 'Africa/Tunis', 'locale' => 'fr', 'taxes' => $tva(7)],
            ['country_code' => 'MG', 'name' => $fr('Madagascar', 'Madagascar'), 'currency' => 'MGA', 'timezone' => 'Indian/Antananarivo', 'locale' => 'fr', 'taxes' => $tva(20)],
            ['country_code' => 'RW', 'name' => $fr('Rwanda', 'Rwanda'), 'currency' => 'RWF', 'timezone' => 'Africa/Kigali', 'locale' => 'en', 'taxes' => $vat(18)],
            ['country_code' => 'KE', 'name' => $fr('Kenya', 'Kenya'), 'currency' => 'KES', 'timezone' => 'Africa/Nairobi', 'locale' => 'en', 'taxes' => [['name' => 'VAT', 'rate' => 16], ['name' => 'Catering levy', 'rate' => 2]]],
            ['country_code' => 'TZ', 'name' => $fr('Tanzanie', 'Tanzania'), 'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'locale' => 'en', 'taxes' => $vat(18)],
            ['country_code' => 'UG', 'name' => $fr('Ouganda', 'Uganda'), 'currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'locale' => 'en', 'taxes' => $vat(18)],
            ['country_code' => 'ET', 'name' => $fr('Éthiopie', 'Ethiopia'), 'currency' => 'ETB', 'timezone' => 'Africa/Addis_Ababa', 'locale' => 'en', 'taxes' => $vat(15)],
            ['country_code' => 'ZA', 'name' => $fr('Afrique du Sud', 'South Africa'), 'currency' => 'ZAR', 'timezone' => 'Africa/Johannesburg', 'locale' => 'en', 'taxes' => $vat(15)],
        ];
    }
}
