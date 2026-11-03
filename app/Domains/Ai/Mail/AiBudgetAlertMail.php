<?php

declare(strict_types=1);

namespace App\Domains\Ai\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Warns hotel managers that AI spending passed 80% of today's limit.
 */
final class AiBudgetAlertMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(
        public readonly string $hotelName,
        public readonly int $spentMicroUsd,
        public readonly int $limitMicroUsd,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('AI spending is close to today’s limit at :hotel', ['hotel' => $this->hotelName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ai-budget-alert', with: [
            'spent' => number_format($this->spentMicroUsd / 1_000_000, 2),
            'limit' => number_format($this->limitMicroUsd / 1_000_000, 2),
        ]);
    }

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }
}
