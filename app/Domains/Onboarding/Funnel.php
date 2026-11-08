<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Assistant\Knowledge\KnowledgeVersion;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Onboarding\Models\PendingSignup;
use App\Domains\Rooms\Models\Room;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;

/**
 * Where self sign-ups are on the way to paying.
 *
 * @phpstan-type Step array{key: string, label: string, count: int}
 */
final class Funnel
{
    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    public static function build(): array
    {
        $counts = ['signed_up' => PendingSignup::query()->count(), 'verified' => 0, 'rooms' => 0, 'whatsapp' => 0, 'pack' => 0, 'golive_requested' => 0, 'live' => 0, 'paying' => 0];
        $context = app(TenantContext::class);

        foreach (PendingSignup::query()->whereNotNull('hotel_id')->pluck('hotel_id') as $hotelId) {
            $hotel = Hotel::query()->whereKey($hotelId)->first();
            if (! $hotel instanceof Hotel) {
                continue;
            }

            $counts['verified']++;
            $context->run($hotel, function () use (&$counts, $hotel): void {
                $counts['rooms'] += Room::query()->exists() ? 1 : 0;
                $counts['whatsapp'] += WhatsAppAccount::query()->where('status', AccountStatus::Connected->value)->exists() ? 1 : 0;
                $counts['pack'] += KnowledgeVersion::query()->whereNotNull('published_at')->exists() ? 1 : 0;
                $counts['golive_requested'] += OnboardingProgress::current()->golive_requested_at !== null ? 1 : 0;
                $counts['live'] += HotelAssistantSettings::current()->mode !== AssistantMode::Off ? 1 : 0;
                $counts['paying'] += Invoice::query()->where('hotel_id', $hotel->id)->where('kind', 'invoice')->where('paid_minor', '>', 0)->exists() ? 1 : 0;
            });
        }

        $labels = [
            'signed_up' => __('Signed up'), 'verified' => __('Email confirmed'), 'rooms' => __('Rooms set'), 'whatsapp' => __('WhatsApp connected'),
            'pack' => __('Information pack published'), 'golive_requested' => __('Asked to go live'), 'live' => __('Live'), 'paying' => __('Paying'),
        ];

        return array_map(fn (string $key): array => ['key' => $key, 'label' => (string) $labels[$key], 'count' => $counts[$key]], array_keys($counts));
    }
}
