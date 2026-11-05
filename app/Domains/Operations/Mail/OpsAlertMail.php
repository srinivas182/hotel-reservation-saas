<?php

declare(strict_types=1);

namespace App\Domains\Operations\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Operations\OpsIncident;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Operations alert to MCS. Sent synchronously (not queued): one of the
 * problems it reports may be a stuck queue.
 */
final class OpsAlertMail extends Mailable
{
    use HasEmailCategory, Queueable;

    /**
     * @param  'opened'|'reminder'|'resolved'  $state
     */
    public function __construct(public readonly OpsIncident $incident, public readonly string $state) {}

    public function envelope(): Envelope
    {
        $prefix = match ($this->state) {
            'opened' => '[PROBLEM]',
            'reminder' => '[STILL A PROBLEM]',
            default => '[RESOLVED]',
        };

        return new Envelope(subject: $prefix.' '.config('app.name').': '.$this->incident->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.ops-alert');
    }

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }
}
