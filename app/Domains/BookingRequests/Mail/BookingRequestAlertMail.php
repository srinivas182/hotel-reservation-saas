<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells reception about a new WhatsApp booking request, or one that
 * expired without an answer.
 */
final class BookingRequestAlertMail extends Mailable
{
    use HasEmailCategory, Queueable;

    /**
     * @param  'new'|'expired'  $kind
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $hotelName,
        public readonly string $reference,
        public readonly string $guest,
        public readonly string $stay,
        public readonly string $total,
        public readonly string $expiresAt,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->kind === 'new'
            ? __('New WhatsApp booking request :reference at :hotel', ['reference' => $this->reference, 'hotel' => $this->hotelName])
            : __('WhatsApp booking request :reference expired without an answer', ['reference' => $this->reference]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.booking-request-alert');
    }

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }
}
