<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts;

use App\Domains\Access\Permission;

enum AlertKind: string
{
    case NewRequest = 'new_request';
    case Handover = 'handover';
    case GuestWaiting = 'guest_waiting';
    case RequestExpired = 'request_expired';
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::NewRequest => __('New WhatsApp booking request'),
            self::Handover => __('Conversation passed to staff'),
            self::GuestWaiting => __('Guest waiting for a reply'),
            self::RequestExpired => __('Booking request expired without an answer'),
            self::Test => __('Test alert'),
        };
    }

    /**
     * Only people who can act on an alert receive it.
     */
    public function permission(): Permission
    {
        return match ($this) {
            self::NewRequest, self::RequestExpired => Permission::BookingRequestsDecide,
            default => Permission::ConversationsReply,
        };
    }

    /**
     * @return list<self>
     */
    public static function choosable(): array
    {
        return [self::NewRequest, self::Handover, self::GuestWaiting, self::RequestExpired];
    }
}
