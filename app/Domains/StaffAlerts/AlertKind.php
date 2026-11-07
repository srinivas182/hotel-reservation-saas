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
    case TableRequest = 'table_request';
    case NewOrder = 'new_order';
    case EventOptionExpired = 'event_option';
    case EventEnquiry = 'event_enquiry';
    case CreditsLow = 'credits_low';
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::NewRequest => __('New WhatsApp booking request'),
            self::Handover => __('Conversation passed to staff'),
            self::GuestWaiting => __('Guest waiting for a reply'),
            self::RequestExpired => __('Booking request expired without an answer'),
            self::TableRequest => __('New table request'),
            self::NewOrder => __('New food or drink order'),
            self::EventOptionExpired => __('Event option expired or space freed'),
            self::EventEnquiry => __('New event enquiry'),
            self::CreditsLow => __('AI or email credits running low'),
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
            self::TableRequest => Permission::RestaurantBookings,
            self::NewOrder => Permission::RestaurantOrders,
            self::EventOptionExpired, self::EventEnquiry => Permission::EventsManage,
            self::CreditsLow => Permission::BillingView,
            default => Permission::ConversationsReply,
        };
    }

    /**
     * @return list<self>
     */
    public static function choosable(): array
    {
        return [self::NewRequest, self::Handover, self::GuestWaiting, self::RequestExpired, self::TableRequest, self::NewOrder, self::EventOptionExpired, self::EventEnquiry, self::CreditsLow];
    }
}
