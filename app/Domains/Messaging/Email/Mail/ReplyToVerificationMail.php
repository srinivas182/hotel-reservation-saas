<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Asks the owner of an address to confirm it may receive guest replies.
 */
final class ReplyToVerificationMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(
        public readonly string $hotelName,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Confirm the reply address for :hotel', ['hotel' => $this->hotelName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.reply-to-verification');
    }

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::Security;
    }
}
