<?php

declare(strict_types=1);

namespace App\Domains\Push;

use App\Domains\StaffAlerts\AlertKind;

/**
 * Where a notification opens, and the signature that lets the phone say
 * "opened" without a session (the service worker has no CSRF token).
 */
final class PushLinks
{
    public static function for(AlertKind $kind, ?string $subjectId): string
    {
        return match ($kind) {
            AlertKind::Handover, AlertKind::GuestWaiting => $subjectId === null ? route('hotel.inbox') : route('hotel.inbox.show', $subjectId),
            AlertKind::TableRequest => route('hotel.restaurant'),
            AlertKind::NewOrder => route('hotel.restaurant.orders'),
            AlertKind::EventOptionExpired, AlertKind::EventEnquiry => $subjectId === null ? route('hotel.today') : route('hotel.events.show', $subjectId),
            AlertKind::CreditsLow => route('hotel.billing'),
            default => route('hotel.today'),
        };
    }

    public static function openedSignature(string $hotelId, int $alertId): string
    {
        return substr(hash_hmac('sha256', 'push-opened|'.$hotelId.'|'.$alertId, (string) config('app.key')), 0, 32);
    }
}
