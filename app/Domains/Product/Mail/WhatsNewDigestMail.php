<?php

declare(strict_types=1);

namespace App\Domains\Product\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Product\Models\ReleaseNote;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/**
 * E: the monthly "What's new" email to owners and managers (they can stop it).
 */
final class WhatsNewDigestMail extends Mailable
{
    use HasEmailCategory, Queueable;

    /**
     * @param  array<int, int>  $noteIds
     */
    public function __construct(public readonly string $userId, public readonly array $noteIds) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $user = User::query()->findOrFail($this->userId);
        app()->setLocale($user->preferred_locale ?? 'fr');

        return new Envelope(subject: (string) __('What’s new this month in :app', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        $user = User::query()->findOrFail($this->userId);
        $locale = $user->preferred_locale ?? 'fr';

        return new Content(markdown: 'mail.product.digest', with: [
            'notes' => ReleaseNote::query()->whereIn('id', $this->noteIds)->orderBy('published_at')->get()->map(fn (ReleaseNote $n): array => ['title' => $n->text('title', $locale), 'body' => $n->text('body', $locale)]),
            'url' => url('/whats-new'),
            'unsubscribe' => URL::signedRoute('product-emails.off', ['user' => $this->userId]),
        ]);
    }
}
