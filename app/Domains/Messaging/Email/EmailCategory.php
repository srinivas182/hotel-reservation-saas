<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

/**
 * Why an email is sent. Security and system emails are never counted or
 * blocked; the others use the hotel's email credits (enforced from S13).
 */
enum EmailCategory: string
{
    /** Invitations, reply-to confirmation, security notices. */
    case Security = 'security';
    /** Framework account emails (password reset, email verification). */
    case System = 'system';
    /** Booking confirmations, reminders and other messages to guests. */
    case Guest = 'guest';
    /** Messages to the hotel's own team, such as the owner's daily summary. */
    case Operational = 'operational';
    /** Promotions sent to guests who opted in. */
    case Marketing = 'marketing';

    public const HEADER = 'X-Email-Category';

    public function countable(): bool
    {
        return in_array($this, [self::Guest, self::Operational, self::Marketing], true);
    }

    public function meter(): string
    {
        return 'email.'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Security => __('Security'),
            self::System => __('Account'),
            self::Guest => __('Guest'),
            self::Operational => __('Operational'),
            self::Marketing => __('Marketing'),
        };
    }
}
