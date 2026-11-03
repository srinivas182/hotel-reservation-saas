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
        ];
    }
}
