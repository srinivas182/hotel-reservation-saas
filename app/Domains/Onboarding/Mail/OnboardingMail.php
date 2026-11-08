<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Mail;

use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Onboarding\SetupChecklist;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Onboarding emails: welcome, setup reminder, trial ending, go-live request
 * (to the platform) and go-live decision (to the owner).
 */
final class OnboardingMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly string $hotelId, public readonly string $kind) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $hotel = Hotel::query()->findOrFail($this->hotelId);

        return new Envelope(subject: (string) match ($this->kind) {
            'welcome' => __('Welcome! Let’s set up :hotel', ['hotel' => $hotel->name]),
            'setup' => __(':hotel: your next setup step', ['hotel' => $hotel->name]),
            'trial_3' => __('Your trial ends in 3 days'),
            'trial_last' => __('Your trial ends today'),
            'golive_request' => __('Go-live request: :hotel', ['hotel' => $hotel->name]),
            'golive_approved' => __(':hotel is live: the assistant starts in supervised mode', ['hotel' => $hotel->name]),
            default => __('Your go-live request needs changes'),
        });
    }

    public function content(): Content
    {
        $hotel = Hotel::query()->with('owner')->findOrFail($this->hotelId);
        [$next, $reason] = app(TenantContext::class)->run($hotel, function (): array {
            $step = collect(app(SetupChecklist::class)->steps())->first(fn (array $s): bool => ! $s['done'] && ! $s['skipped']);

            return [$step === null ? null : $step['title'], OnboardingProgress::current()->golive_reason];
        });

        return new Content(markdown: 'mail.onboarding.message', with: [
            'hotel' => $hotel,
            'kind' => $this->kind,
            'next' => $next,
            'reason' => $reason,
            'url' => match ($this->kind) {
                'golive_request' => route('platform.signups.index'),
                'trial_3', 'trial_last' => route('hotel.billing'),
                default => route('hotel.setup'),
            },
        ]);
    }
}
