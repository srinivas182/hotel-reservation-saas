<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\GoLive\Readiness;
use App\Domains\Onboarding\Mail\OnboardingMail;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Onboarding\Models\SignupSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Asking to go live: the required checks must pass and the owner must use
 * two-step login; then the platform approves (or it is automatic, as set),
 * and the assistant starts in supervised mode.
 */
final class GoLive
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return list<string> what still blocks the request
     */
    public function blockers(User $owner): array
    {
        // A platform override (S8.1) lifts the readiness checks, not two-step login.
        $overridden = HotelAssistantSettings::current()->readiness_override_at !== null;
        $blockers = $overridden ? [] : array_values(array_map(fn ($c): string => $c->label, array_filter(app(Readiness::class)->checks(), fn ($c): bool => $c->required && ! $c->ok)));

        if (! $owner->hasEnabledTwoFactorAuthentication()) {
            $blockers[] = (string) __('Two-step login for your account');
        }

        return $blockers;
    }

    public function request(User $owner): OnboardingProgress
    {
        if (($blockers = $this->blockers($owner)) !== []) {
            throw new RuntimeException((string) __('Still to do: :items', ['items' => implode(', ', $blockers)]));
        }

        $progress = OnboardingProgress::current();
        $progress->forceFill(['golive_requested_at' => now(), 'golive_decision' => null, 'golive_decided_at' => null, 'golive_reason' => null])->save();
        $hotel = $this->context->hotel();
        Audit::record('onboarding.golive_requested', 'Go-live requested', subject: $hotel, causer: $owner);

        $settings = SignupSettings::current();
        if (! $settings->approval_required) {
            return $this->decide($hotel, true, null, null);
        }

        foreach ($settings->notify_emails ?? [] as $to) {
            Mail::to($to)->queue(new OnboardingMail($hotel->id, 'golive_request'));
        }

        return $progress;
    }

    public function decide(Hotel $hotel, bool $approve, ?string $reason, ?User $by): OnboardingProgress
    {
        return $this->context->run($hotel, function () use ($hotel, $approve, $reason, $by): OnboardingProgress {
            $progress = OnboardingProgress::current();

            if ($progress->golive_requested_at === null) {
                throw new RuntimeException('No go-live request.');
            }

            $progress->forceFill(['golive_decision' => $approve ? 'approved' : 'refused', 'golive_decided_at' => now(), 'golive_reason' => $reason])->save();

            if ($approve) {
                $settings = HotelAssistantSettings::current();
                if ($settings->mode === AssistantMode::Off) {
                    $settings->forceFill(['mode' => AssistantMode::Supervised])->save();
                }
            }

            Audit::record($approve ? 'onboarding.golive_approved' : 'onboarding.golive_refused', $approve ? 'Go-live approved' : 'Go-live refused', subject: $hotel, causer: $by, properties: ['reason' => $reason]);

            if ($hotel->owner?->email !== null) {
                Mail::to($hotel->owner->email)->queue(new OnboardingMail($hotel->id, $approve ? 'golive_approved' : 'golive_refused'));
            }

            return $progress;
        });
    }
}
