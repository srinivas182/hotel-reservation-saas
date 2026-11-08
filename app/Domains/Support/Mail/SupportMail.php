<?php

declare(strict_types=1);

namespace App\Domains\Support\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Support\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Support emails: to the hotel (reply, solved) and to the team (new request, hotel replied).
 */
final class SupportMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly int $ticketId, public readonly string $kind) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $ticket = SupportTicket::query()->with('hotel')->findOrFail($this->ticketId);

        return new Envelope(subject: (string) match ($this->kind) {
            'team_opened' => __('New support request #:id from :hotel: :subject', ['id' => $ticket->id, 'hotel' => $ticket->hotel?->name, 'subject' => $ticket->subject]),
            'team_replied' => __(':hotel replied on request #:id', ['id' => $ticket->id, 'hotel' => $ticket->hotel?->name]),
            'solved' => __('Your request #:id is solved — how did we do?', ['id' => $ticket->id]),
            default => __('Support answered your request #:id', ['id' => $ticket->id]),
        });
    }

    public function content(): Content
    {
        $ticket = SupportTicket::query()->with(['hotel', 'messages'])->findOrFail($this->ticketId);
        $last = $ticket->messages->where('internal', false)->last();
        $team = str_starts_with($this->kind, 'team_');

        return new Content(markdown: 'mail.support.message', with: [
            'ticket' => $ticket, 'kind' => $this->kind, 'excerpt' => $last === null ? '' : \Illuminate\Support\Str::limit($last->body, 600),
            'url' => $team ? route('platform.support.show', $ticket) : route('hotel.requests.show', $ticket),
        ]);
    }
}
