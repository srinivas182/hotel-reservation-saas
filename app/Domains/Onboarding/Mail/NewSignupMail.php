<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Tenancy\Models\Hotel;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the platform team a hotel signed up.
 */
final class NewSignupMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly string $hotelId) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('New hotel sign-up: :hotel', ['hotel' => Hotel::query()->find($this->hotelId)?->name]));
    }

    public function content(): Content
    {
        $hotel = Hotel::query()->with('owner')->findOrFail($this->hotelId);

        return new Content(markdown: 'mail.onboarding.new-signup', with: ['hotel' => $hotel, 'url' => route('platform.signups.index')]);
    }
}
