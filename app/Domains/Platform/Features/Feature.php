<?php

declare(strict_types=1);

namespace App\Domains\Platform\Features;

/**
 * Features the platform can roll out gradually. With no switch set, a
 * feature is on (as before switches existed).
 */
enum Feature: string
{
    case AutomaticMode = 'automatic_mode';
    case VoiceNotes = 'voice_notes';
    case WhatsAppRestaurant = 'whatsapp_restaurant';
    case WhatsAppEvents = 'whatsapp_events';

    public function label(): string
    {
        return match ($this) {
            self::AutomaticMode => __('Automatic mode (assistant replies without approval)'),
            self::VoiceNotes => __('Voice notes understood by the assistant'),
            self::WhatsAppRestaurant => __('Restaurant tables, menu and orders on WhatsApp'),
            self::WhatsAppEvents => __('Event enquiries on WhatsApp'),
        };
    }
}
