<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Templates;

/**
 * Templates the platform offers every hotel, per language. Booking and
 * stay templates are added in S6 and S7.
 */
final class StarterTemplates
{
    /**
     * @return array<string, array{name: string, category: string, label: string, bodies: array<string, string>, examples: list<string>}>
     */
    public static function all(): array
    {
        return [
            'message_received' => [
                'name' => 'message_received',
                'category' => 'UTILITY',
                'label' => __('We received your message'),
                'bodies' => [
                    'en' => 'Hello {{1}}, thank you for your message to {{2}}. We will reply as soon as possible.',
                    'fr' => 'Bonjour {{1}}, merci pour votre message à {{2}}. Nous vous répondrons dès que possible.',
                ],
                'examples' => ['Awa', 'First Class Lodge'],
            ],
            'booking_confirmed' => [
                'name' => 'booking_confirmed',
                'category' => 'UTILITY',
                'label' => __('Booking confirmed'),
                'bodies' => [
                    'en' => 'Hello {{1}}, your booking {{2}} is confirmed: {{3}}, from {{4}} to {{5}}. Total: {{6}}. See you soon at {{7}}!',
                    'fr' => 'Bonjour {{1}}, votre réservation {{2}} est confirmée : {{3}}, du {{4}} au {{5}}. Total : {{6}}. À bientôt à {{7}} !',
                ],
                'examples' => ['Awa', 'FCL-26-0042', 'Executive', '12 November 2026', '14 November 2026', '50,000 F CFA', 'First Class Lodge'],
            ],
            'booking_deposit_request' => [
                'name' => 'booking_deposit_request',
                'category' => 'UTILITY',
                'label' => __('Deposit request'),
                'bodies' => [
                    'en' => 'Hello {{1}}, {{2}} has accepted your request {{3}} ({{4}}, from {{5}} to {{6}}). To confirm it, please pay a deposit of {{7}} before {{8}}. How to pay: {{9}}. Thank you!',
                    'fr' => 'Bonjour {{1}}, {{2}} accepte votre demande {{3}} ({{4}}, du {{5}} au {{6}}). Pour la confirmer, merci de verser un acompte de {{7}} avant le {{8}}. Comment payer : {{9}}. Merci !',
                ],
                'examples' => ['Awa', 'First Class Lodge', 'FCL-26-0042', 'Executive', '12 November 2026', '14 November 2026', '15,000 F CFA', '2 November 18:00', 'Orange Money 07 00 00 00 00'],
            ],
            'booking_declined' => [
                'name' => 'booking_declined',
                'category' => 'UTILITY',
                'label' => __('Booking request declined'),
                'bodies' => [
                    'en' => 'Hello {{1}}, we are sorry: {{2}} cannot accept your request from {{3}} to {{4}}. Do not hesitate to write to us for other dates.',
                    'fr' => 'Bonjour {{1}}, nous sommes désolés : {{2}} ne peut pas accepter votre demande du {{3}} au {{4}}. N\'hésitez pas à nous écrire pour d\'autres dates.',
                ],
                'examples' => ['Awa', 'First Class Lodge', '12 November 2026', '14 November 2026'],
            ],
            'request_expired' => [
                'name' => 'request_expired',
                'category' => 'UTILITY',
                'label' => __('Booking request not answered in time'),
                'bodies' => [
                    'en' => 'Hello {{1}}, thank you for your request from {{2}} to {{3}}. Our reception will contact you very soon to finalise it.',
                    'fr' => 'Bonjour {{1}}, merci pour votre demande du {{2}} au {{3}}. Notre réception vous contactera très vite pour la finaliser.',
                ],
                'examples' => ['Awa', '12 November 2026', '14 November 2026'],
            ],
        ];
    }
}
