<?php

declare(strict_types=1);

namespace App\Domains\Status\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Status\Models\StatusNotice;
use App\Domains\Status\Models\StatusSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Status emails: confirm a subscription, or an incident update.
 */
final class StatusMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly int $subscriberId, public readonly ?int $noticeId, public readonly ?string $update = null) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $subscriber = StatusSubscriber::query()->findOrFail($this->subscriberId);
        app()->setLocale($subscriber->locale);
        $notice = $this->noticeId === null ? null : StatusNotice::query()->find($this->noticeId);

        return new Envelope(subject: $notice === null ? (string) __('Confirm your status alerts') : (string) __('Status: :title', ['title' => $notice->text($subscriber->locale)]));
    }

    public function content(): Content
    {
        $subscriber = StatusSubscriber::query()->findOrFail($this->subscriberId);
        $notice = $this->noticeId === null ? null : StatusNotice::query()->find($this->noticeId);
        $base = $subscriber->locale === 'en' ? '/en/status' : '/status';

        return new Content(markdown: 'mail.status', with: [
            'notice' => $notice, 'locale' => $subscriber->locale, 'update' => $this->update,
            'confirm' => url($base.'/confirm/'.$subscriber->token), 'unsubscribe' => url($base.'/unsubscribe/'.$subscriber->token), 'page' => url($base),
        ]);
    }
}
