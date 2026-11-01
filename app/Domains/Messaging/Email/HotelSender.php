<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Mail\Mailables\Address;

/**
 * The hotel's sender identity for emails sent on its behalf.
 *
 * The sending address always stays on the platform's verified domain (the
 * provider's from address); hotels choose the display name and where
 * replies go. An unconfirmed reply-to address is never used.
 */
final class HotelSender
{
    public function __construct(private readonly TenantContext $context) {}

    public function from(Hotel $hotel): Address
    {
        $settings = $this->settings($hotel);
        $name = $settings?->email_sender_name;

        return new Address((string) config('mail.from.address'), $name !== null && $name !== '' ? $name : $hotel->name);
    }

    public function replyTo(Hotel $hotel): ?Address
    {
        $settings = $this->settings($hotel);

        if ($settings === null) {
            return null;
        }

        if ($settings->email_reply_to !== null && $settings->email_reply_to_verified_at !== null) {
            return new Address($settings->email_reply_to, $this->from($hotel)->name);
        }

        return $settings->email !== null ? new Address($settings->email, $this->from($hotel)->name) : null;
    }

    private function settings(Hotel $hotel): ?HotelSettings
    {
        return $this->context->run($hotel, fn (): ?HotelSettings => HotelSettings::query()->first());
    }
}
