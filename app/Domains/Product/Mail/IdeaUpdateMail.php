<?php

declare(strict_types=1);

namespace App\Domains\Product\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Product\Models\Idea;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class IdeaUpdateMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly int $ideaId, public readonly string $userId) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $user = User::query()->findOrFail($this->userId);
        app()->setLocale($user->preferred_locale ?? 'fr');
        $idea = Idea::query()->findOrFail($this->ideaId);

        return new Envelope(subject: (string) ($idea->status === 'done' ? __('An idea you voted for is available: :title', ['title' => $idea->title]) : __('An idea you voted for is planned: :title', ['title' => $idea->title])));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.product.idea', with: ['idea' => Idea::query()->findOrFail($this->ideaId), 'url' => url('/ideas'), 'unsubscribe' => \Illuminate\Support\Facades\URL::signedRoute('product-emails.off', ['user' => $this->userId])]);
    }
}
