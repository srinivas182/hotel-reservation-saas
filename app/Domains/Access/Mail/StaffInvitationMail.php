<?php

declare(strict_types=1);

namespace App\Domains\Access\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invitation to join a hotel. Sent immediately (not queued): it is a
 * security email and the link must reach the person even if no queue
 * worker is running.
 */
final class StaffInvitationMail extends Mailable
{
    use HasEmailCategory, Queueable, SerializesModels;

    public function __construct(
        public readonly string $hotelName,
        public readonly string $roleName,
        public readonly string $url,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('You are invited to join :hotel', ['hotel' => $this->hotelName]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.staff-invitation');
    }

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::Security;
    }
}
