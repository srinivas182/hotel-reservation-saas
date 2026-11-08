<?php

declare(strict_types=1);

namespace App\Domains\Site\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LeadsDigestMail extends Mailable
{
    use HasEmailCategory, Queueable;

    /**
     * @param  array<int, array{hotel: string, name: string, whatsapp: string, status: string, follow_up: string|null}>  $new
     * @param  array<int, array{hotel: string, name: string, whatsapp: string, status: string, follow_up: string|null}>  $overdue
     */
    public function __construct(public readonly array $new, public readonly array $overdue) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('Leads: :new new, :overdue to follow up', ['new' => count($this->new), 'overdue' => count($this->overdue)]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.leads-digest', with: ['new' => $this->new, 'overdue' => $this->overdue, 'url' => route('platform.leads')]);
    }
}
