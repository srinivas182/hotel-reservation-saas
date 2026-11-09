<?php

declare(strict_types=1);

namespace App\Domains\Sales\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Sales\Models\Proposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ProposalMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly int $proposalId) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $p = Proposal::query()->with('lead')->findOrFail($this->proposalId);
        app()->setLocale($p->locale);

        return new Envelope(subject: (string) __('Your proposal for :hotel', ['hotel' => $p->lead->hotel_name]));
    }

    public function content(): Content
    {
        $p = Proposal::query()->with('lead')->findOrFail($this->proposalId);

        return new Content(markdown: 'mail.proposal', with: ['p' => $p, 'url' => url('/proposal/'.$p->token)]);
    }
}
