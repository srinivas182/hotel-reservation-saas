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
            'staff_alert' => [
                'name' => 'staff_alert',
                'category' => 'UTILITY',
                'label' => __('Alert to hotel staff'),
                'bodies' => [
                    'en' => 'Alert for {{1}}: {{2}}. Details: {{3}}. Open the dashboard to respond.',
                    'fr' => 'Alerte pour {{1}} : {{2}}. Détails : {{3}}. Ouvrez le tableau de bord pour répondre.',
                ],
                'examples' => ['First Class Lodge', 'New WhatsApp booking request', 'Awa Koné, Executive, 2026-11-12 → 2026-11-14'],
            ],
            'owner_daily_summary' => [
                'name' => 'owner_daily_summary',
                'category' => 'UTILITY',
                'label' => __('Owner\'s daily summary'),
                'bodies' => [
                    'en' => 'Summary for {{1}} on {{2}}: {{3}} arrivals, {{4}} departures, rooms occupied tonight {{5}}. New bookings: {{6}} worth {{7}}. WhatsApp requests approved/declined/expired: {{8}}. Conversations needing attention: {{9}}. AI cost today: {{10}}. Good evening.',
                    'fr' => 'Résumé de {{1}} pour le {{2}} : {{3}} arrivées, {{4}} départs, chambres occupées ce soir {{5}}. Nouvelles réservations : {{6}} pour {{7}}. Demandes WhatsApp approuvées/refusées/expirées : {{8}}. Conversations à traiter : {{9}}. Coût de l\'IA aujourd\'hui : {{10}}. Bonne soirée.',
                ],
                'examples' => ['First Class Lodge', '12 November 2026', '4', '3', '18/26', '5', '250,000 F CFA', '3/1/0', '1', 'US$ 0.42'],
            ],
            'stay_reminder' => [
                'name' => 'stay_reminder',
                'category' => 'UTILITY',
                'label' => __('Arrival reminder'),
                'bodies' => [
                    'en' => 'Hello {{1}}, we look forward to welcoming you at {{2}} tomorrow ({{3}}). Check-in is from {{4}}. Directions: {{5}}. Reply to this message if you need anything.',
                    'fr' => 'Bonjour {{1}}, nous avons hâte de vous accueillir à {{2}} demain ({{3}}). L\'arrivée est possible à partir de {{4}}. Itinéraire : {{5}}. Répondez à ce message si vous avez besoin de quoi que ce soit.',
                ],
                'examples' => ['Awa', 'First Class Lodge', 'Thursday 12 November', '14:00', 'https://maps.google.com/?q=First+Class+Lodge+Korhogo'],
            ],
            'checkout_reminder' => [
                'name' => 'checkout_reminder',
                'category' => 'UTILITY',
                'label' => __('Check-out reminder'),
                'bodies' => [
                    'en' => 'Good morning {{1}}, check-out at {{2}} is until {{3}} today. Reply to this message if you would like a late check-out. Thank you for your stay!',
                    'fr' => 'Bonjour {{1}}, le départ de {{2}} est prévu jusqu\'à {{3}} aujourd\'hui. Répondez à ce message si vous souhaitez un départ tardif. Merci pour votre séjour !',
                ],
                'examples' => ['Awa', 'First Class Lodge', '12:00'],
            ],
            'stay_thank_you' => [
                'name' => 'stay_thank_you',
                'category' => 'UTILITY',
                'label' => __('Thank-you after the stay'),
                'bodies' => [
                    'en' => 'Thank you {{1}} for staying at {{2}}. We hope to see you again soon. Your opinion helps us: {{3}}. Have a good trip!',
                    'fr' => 'Merci {{1}} pour votre séjour à {{2}}. Nous espérons vous revoir bientôt. Votre avis nous aide : {{3}}. Bon voyage !',
                ],
                'examples' => ['Awa', 'First Class Lodge', 'https://g.page/r/first-class-lodge/review'],
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
