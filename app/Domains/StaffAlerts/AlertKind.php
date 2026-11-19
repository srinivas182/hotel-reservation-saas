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
    case DepositPaid = 'deposit_paid';
    case DepositProof = 'deposit_proof';
    case DepositRefund = 'deposit_refund';
    case ArrivalCancelling = 'arrival_cancelling';
    case ArrivalRequest = 'arrival_request';
    case ArrivalUnconfirmed = 'arrival_unconfirmed';
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
            self::DepositPaid => __('Deposit paid by a guest'),
            self::DepositProof => __('Guest sent a payment screenshot to check'),
            self::DepositRefund => __('Deposit to refund after a cancellation'),
            self::ArrivalCancelling => __('A guest arriving tomorrow says they are not coming'),
            self::ArrivalRequest => __('A guest arriving tomorrow asks for something (pickup, early check-in, question)'),
            self::ArrivalUnconfirmed => __('Arrivals of tomorrow who did not confirm (evening)'),
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
            self::DepositPaid => Permission::BookingsView,
            self::DepositProof, self::DepositRefund => Permission::FolioPayments,
            self::ArrivalCancelling, self::ArrivalRequest, self::ArrivalUnconfirmed => Permission::BookingsView,
            default => Permission::ConversationsReply,
        };
    }

    /**
     * @return list<self>
     */
    public static function choosable(): array
    {
        return [self::NewRequest, self::Handover, self::GuestWaiting, self::RequestExpired, self::TableRequest, self::NewOrder, self::EventOptionExpired, self::EventEnquiry, self::DepositPaid, self::DepositProof, self::DepositRefund, self::ArrivalCancelling, self::ArrivalRequest, self::ArrivalUnconfirmed, self::CreditsLow];
    }
}
