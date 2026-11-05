<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OwnerSummaryMail extends Mailable
{
    use HasEmailCategory, Queueable;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(public readonly string $hotelName, public readonly array $summary) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':hotel: summary for :date', ['hotel' => $this->hotelName, 'date' => $this->summary['date']]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.owner-summary', with: ['s' => $this->summary]);
    }

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }
}
