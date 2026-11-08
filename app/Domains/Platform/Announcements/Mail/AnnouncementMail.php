<?php

declare(strict_types=1);

namespace App\Domains\Platform\Announcements\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Platform\Announcements\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class AnnouncementMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly int $announcementId) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: Announcement::query()->findOrFail($this->announcementId)->text('title', app()->getLocale()));
    }

    public function content(): Content
    {
        $a = Announcement::query()->findOrFail($this->announcementId);

        return new Content(markdown: 'mail.announcement', with: ['title' => $a->text('title', app()->getLocale()), 'body' => $a->text('body', app()->getLocale())]);
    }
}
