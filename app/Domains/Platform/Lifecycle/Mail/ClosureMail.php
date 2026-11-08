<?php

declare(strict_types=1);

namespace App\Domains\Platform\Lifecycle\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Platform\Lifecycle\HotelClosure;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ClosureMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly int $closureId, public readonly string $kind) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $closure = HotelClosure::query()->with('hotel')->findOrFail($this->closureId);

        return new Envelope(subject: $this->kind === 'closed'
            ? (string) __(':hotel is closed: download your data', ['hotel' => $closure->hotel?->name])
            : (string) __(':hotel: your data will be deleted on :date', ['hotel' => $closure->hotel?->name, 'date' => $closure->delete_after->toDateString()]));
    }

    public function content(): Content
    {
        $closure = HotelClosure::query()->with('hotel')->findOrFail($this->closureId);

        return new Content(markdown: 'mail.hotel-closure', with: ['closure' => $closure, 'url' => route('account.hotels')]);
    }
}
