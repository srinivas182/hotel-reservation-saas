<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\StaffActions\StaffActionRouter;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\StaffAlerts\StaffCard;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;

/**
 * "Check your account" card (Sprint F): a guest sent a payment
 * screenshot on WhatsApp. Staff look at their Wave or Orange account, then
 * answer Received or Not received.
 */
final class DepositProofCard implements StaffCard
{
    public function __construct(private readonly DepositLink $link) {}

    public function for(User $user, StaffAlertSettings $settings, string $locale): ?array
    {
        if (! $settings->approvesOnWhatsApp($user) || ! $user->can('folio.payments')) {
            return null;
        }

        $link = $this->link->loadMissing('booking.guest');
        $valid = max(600, (int) now()->diffInSeconds($link->expires_at ?? now()->addDay(), false));
        $buttons = app(StaffActionRouter::class)->buttons($link->id, $user, $valid, 'dp_received', (string) __('Received', locale: $locale), 'dp_missing', (string) __('Not received', locale: $locale));

        return [
            'text' => implode("\n", self::lines($link, $locale)),
            'buttons' => $buttons,
            'template' => 'deposit_proof_check',
            'values' => [app(TenantContext::class)->hotel()->name, self::summary($link, $locale)],
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(DepositLink $link, string $locale): array
    {
        $hotel = app(TenantContext::class)->hotel();

        return array_values(array_filter([
            (string) __('💳 Payment to check — :hotel', ['hotel' => $hotel->name], $locale),
            (string) __(':guest sent a payment screenshot on WhatsApp.', ['guest' => (string) $link->booking->guest?->name()], $locale),
            (string) __('Booking :reference · deposit :deposit', ['reference' => $link->booking->reference, 'deposit' => MoneyFormatter::format(Money::ofMinor($link->deposit_minor, $link->currency), $locale)], $locale)
                .($link->expires_at === null ? '' : ' · '.__('held until :time', ['time' => $link->expires_at->setTimezone($hotel->timezone)->format('d/m H:i')], $locale)),
            (string) __('Check your account first, then answer.', locale: $locale),
        ]));
    }

    public static function summary(DepositLink $link, string $locale): string
    {
        return (string) __(':guest sent a screenshot, booking :reference, deposit :deposit', [
            'guest' => (string) $link->booking->guest?->name(),
            'reference' => $link->booking->reference,
            'deposit' => MoneyFormatter::format(Money::ofMinor($link->deposit_minor, $link->currency), $locale),
        ], $locale);
    }
}
