<?php

declare(strict_types=1);

namespace App\Domains\Deposits;

use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Deposits received, by day or month and by method (Sprint F): the
 * morning summary line, the month's totals and the accountant's export.
 * Days and months are the hotel's.
 */
final class Reconciliation
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Deposits paid on one hotel day, by method: [method => [count, minor]].
     *
     * @return array<string, array{count: int, minor: int}>
     */
    public function day(CarbonImmutable $day): array
    {
        return $this->byMethod($this->paidBetween($day->startOfDay(), $day->startOfDay()->addDay()));
    }

    /**
     * @return array{count: int, total: string|null, refunds_due: int, by_method: list<array{method: string, count: int, total: string}>}
     */
    public function month(CarbonImmutable $month): array
    {
        $links = $this->paidBetween($month->startOfMonth(), $month->startOfMonth()->addMonth());
        $currency = $this->context->hotel()->currency;
        $format = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $currency), $this->context->hotel()->default_locale);
        $byMethod = [];

        foreach ($this->byMethod($links) as $method => $row) {
            $byMethod[] = ['method' => RestaurantSettings::methodLabel($method), 'count' => $row['count'], 'total' => $format($row['minor'])];
        }

        $sum = array_sum(array_map(fn (DepositLink $l): int => (int) $l->paid_minor, $links));

        return [
            'count' => count($links),
            'total' => $links === [] ? null : $format($sum),
            'refunds_due' => DepositLink::query()->where('refund_status', 'due')->count(),
            'by_method' => $byMethod,
        ];
    }

    /**
     * The export: one line per deposit paid in the month.
     *
     * @return list<list<string>>
     */
    public function rows(CarbonImmutable $month): array
    {
        $hotel = $this->context->hotel();
        $rows = [[
            (string) __('Paid at'), (string) __('Receipt'), (string) __('Booking'), (string) __('Guest'), (string) __('Method'),
            (string) __('How'), (string) __('Amount'), (string) __('Currency'), (string) __('Provider reference'), (string) __('Confirmed by'), (string) __('Refund'),
        ]];

        foreach ($this->paidBetween($month->startOfMonth(), $month->startOfMonth()->addMonth()) as $link) {
            $rows[] = [
                (string) $link->paid_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
                (string) $link->receipt_number,
                $link->booking->reference,
                (string) $link->booking->guest?->name(),
                RestaurantSettings::methodLabel((string) $link->method),
                $link->paid_via === 'manual' ? (string) __('Confirmed by staff') : (string) __('Online'),
                // Amounts in major units with no thousands separator, for spreadsheets.
                (string) Money::ofMinor((int) $link->paid_minor, $link->currency)->getAmount(),
                $link->currency,
                (string) $link->provider_reference,
                (string) $link->confirmer?->name,
                match ($link->refund_status) {
                    'due' => (string) __('to refund'),
                    'done' => (string) __('refunded'),
                    'kept' => (string) __('kept'),
                    default => '',
                },
            ];
        }

        return $rows;
    }

    /**
     * @return list<DepositLink>
     */
    private function paidBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $this->context->hotel()->timezone;

        return array_values(DepositLink::query()->with(['booking.guest', 'confirmer'])
            ->where('status', 'paid')
            ->where('paid_at', '>=', $from->shiftTimezone($tz)->utc())
            ->where('paid_at', '<', $to->shiftTimezone($tz)->utc())
            ->orderBy('paid_at')->get()->all());
    }

    /**
     * @param  list<DepositLink>  $links
     * @return array<string, array{count: int, minor: int}>
     */
    private function byMethod(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            $method = (string) ($link->method ?? 'mobile_money');
            $out[$method] ??= ['count' => 0, 'minor' => 0];
            $out[$method]['count']++;
            $out[$method]['minor'] += (int) $link->paid_minor;
        }
        uasort($out, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $out;
    }
}
