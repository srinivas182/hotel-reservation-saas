<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts\Morning;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\StaffActions\ActionToken;
use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffActions\StaffActionFailed;
use App\Domains\StaffActions\StaffActionHandler;
use App\Domains\StaffActions\StaffActionRouter;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Brick\Money\Money;

/**
 * The detail buttons of the morning summary (Sprint C): today's arrivals,
 * who is in-house, and what is still to be paid. Read-only: the answer is
 * sent at once, nothing is scheduled.
 */
final class MorningSummaryActions implements StaffActionHandler
{
    private const MAX_LINES = 25;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffActionRouter $router,
        private readonly MorningSummary $summary,
    ) {}

    public function prefix(): string
    {
        return 'mx';
    }

    public function kind(): string
    {
        return 'morning_summary';
    }

    public static function canSeeMoney(User $user): bool
    {
        return $user->can('billing.view');
    }

    public function tap(ActionToken $token, User $user, StaffAlertSettings $settings): void
    {
        $locale = app()->getLocale();
        $money = self::canSeeMoney($user);
        $hotel = $this->context->hotel();
        $format = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $hotel->currency), $locale);
        $stay = fn (Booking $b): string => sprintf('%s · %s · %s → %s', (string) $b->guest?->name(), (string) $b->category?->localizedName($locale, $hotel->default_locale), $b->check_in->format('d/m'), $b->check_out->format('d/m'));

        [$title, $lines] = match ($token->action) {
            'mx_arrivals' => [(string) __('Arrivals today'), $this->summary->arrivals()->map(fn (Booking $b): string => $stay($b).($b->status->value === 'tentative' ? ' · '.__('deposit not received') : ''))->all()],
            'mx_inhouse' => [(string) __('In-house'), $this->summary->inHouse()->map($stay)->all()],
            'mx_collect' => $money
                ? [(string) __('To collect today'), array_map(fn (array $d): string => $stay($d['booking']).' · '.$format($d['balance']).' · '.($d['arriving'] ? __('arriving') : __('leaving')), $this->summary->toCollect())]
                : [(string) __('To collect today'), null],
            default => [null, []],
        };

        if ($title === null) {
            return;
        }

        if ($lines === null) {
            $this->router->reply($settings, (string) __('Amounts are only shown to those who manage the hotel\'s money.'));

            return;
        }

        $more = count($lines) - self::MAX_LINES;
        $body = $lines === []
            ? (string) __('None.')
            : implode("\n", array_map(fn (string $l): string => '• '.$l, array_slice($lines, 0, self::MAX_LINES))).($more > 0 ? "\n".__('… and :count more in the dashboard: :url', ['count' => $more, 'url' => route('hotel.today')]) : '');

        $this->router->reply($settings, "*{$title}*\n{$body}");
    }

    public function execute(StaffAction $action, User $user): string
    {
        // Nothing is ever scheduled by these buttons.
        throw new StaffActionFailed((string) __('This request no longer exists.'));
    }
}
