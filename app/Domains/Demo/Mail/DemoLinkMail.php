<?php

declare(strict_types=1);

namespace App\Domains\Demo\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Site\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DemoLinkMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly int $leadId, public readonly string $link) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $lead = Lead::query()->findOrFail($this->leadId);
        app()->setLocale($lead->locale);

        return new Envelope(subject: (string) __('Your private demo for :hotel', ['hotel' => $lead->hotel_name]));
    }

    public function content(): Content
    {
        $lead = Lead::query()->findOrFail($this->leadId);

        return new Content(markdown: 'mail.demo-link', with: ['lead' => $lead, 'link' => $this->link]);
    }
}
