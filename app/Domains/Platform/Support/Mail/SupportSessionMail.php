<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Platform\Support\SupportSession;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the owner a support session started (or asks them to approve it).
 */
final class SupportSessionMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly string $sessionId, public readonly string $kind) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::Security;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) ($this->kind === 'approval' ? __('Our support team asks to access your hotel account') : __('Our support team is helping in your hotel account')));
    }

    public function content(): Content
    {
        $session = SupportSession::query()->with(['user', 'hotel'])->findOrFail($this->sessionId);

        return new Content(markdown: 'mail.support-session', with: ['session' => $session, 'kind' => $this->kind, 'url' => route('hotel.support.index')]);
    }
}
