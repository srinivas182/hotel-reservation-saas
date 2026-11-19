<?php

declare(strict_types=1);

namespace App\Domains\StaffAlerts\Morning;

use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;

/**
 * The words of the morning summary: the full message (inside the 24-hour
 * window), one line for the template, and the short line of a quiet day.
 * Money lines only for people allowed to see the hotel's money.
 */
final class MorningSummaryText
{
    /**
     * @param  array<string, mixed>  $s  from MorningSummary::build()
     */
    public static function message(array $s, string $name, string $locale, bool $money): string
    {
        $hotel = app(TenantContext::class)->hotel();
        $format = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $hotel->currency), $locale);
        $date = $s['today']->settings(['locale' => $locale])->isoFormat('dddd D MMMM');

        if ($s['quiet']) {
            return implode("\n", [(string) __('☀️ Good morning :name — :hotel, :date. Calm day: no arrivals, departures or requests. Tonight: :occupied/:rooms rooms (:percent%).', ['name' => $name, 'hotel' => $hotel->name, 'date' => $date, 'occupied' => $s['occupied'], 'rooms' => $s['rooms'], 'percent' => $s['percent']], $locale), ...self::depositLines($s, $locale, $money, $format), ...self::checklistLines($s, $locale)]);
        }

        $lines = [
            __('☀️ Good morning :name — :hotel, :date', ['name' => $name, 'hotel' => $hotel->name, 'date' => $date], $locale),
            __('Today: :plan', ['plan' => implode(' · ', [
                trans_choice(':count arrival|:count arrivals', $s['arrivals'], [], $locale),
                trans_choice(':count departure|:count departures', $s['departures'], [], $locale),
                trans_choice(':count guest in-house|:count guests in-house', $s['in_house'], [], $locale),
            ])], $locale),
            __('Tonight: :occupied/:rooms rooms (:percent%)', ['occupied' => $s['occupied'], 'rooms' => $s['rooms'], 'percent' => $s['percent']], $locale)
                .($money && $s['percent_last_week'] !== null ? ' · '.self::points($s['percent'] - $s['percent_last_week'], $locale) : ''),
        ];

        if ($money) {
            $parts = [];
            foreach ($s['collected_by_method'] as $method => $value) {
                $parts[] = RestaurantSettings::methodLabel((string) $method).' '.$format((int) $value);
            }
            $methods = implode(' · ', $parts);
            $lines[] = __('Yesterday: collected :total', ['total' => $format($s['collected'])], $locale)
                .($methods !== '' && count($s['collected_by_method']) > 1 ? " ({$methods})" : '')
                .($s['collected_last_week'] > 0 ? ' · '.self::change($s['collected'], $s['collected_last_week'], $locale) : '');

            if ($s['to_collect'] > 0) {
                $lines[] = trans_choice('💰 :count guest still owes :total today.|💰 :count guests still owe :total today.', $s['to_collect_guests'], ['total' => $format($s['to_collect'])], $locale);
            }
        }

        $lines[] = __('Next 7 nights:', locale: $locale);
        foreach ($s['week'] as $date => $percent) {
            $lines[] = \Carbon\CarbonImmutable::parse($date)->settings(['locale' => $locale])->isoFormat('ddd D').' '.self::bar($percent).' '.$percent.' %';
        }

        if ($s['weak'] !== []) {
            $lines[] = __('Under :percent%: :nights', ['percent' => MorningSummary::WEAK_PERCENT, 'nights' => implode(', ', array_map(fn (string $d): string => \Carbon\CarbonImmutable::parse($d)->settings(['locale' => $locale])->isoFormat('dddd D'), $s['weak']))], $locale);
        }

        $lines = [...$lines, ...self::depositLines($s, $locale, $money, $format), ...self::checklistLines($s, $locale)];

        if ($s['pending_requests'] > 0 || $s['attention'] > 0) {
            $lines[] = '⚠️ '.implode(' · ', array_filter([
                $s['pending_requests'] > 0 ? trans_choice(':count WhatsApp request waiting|:count WhatsApp requests waiting', $s['pending_requests'], [], $locale) : null,
                $s['attention'] > 0 ? trans_choice(':count conversation needs you|:count conversations need you', $s['attention'], [], $locale) : null,
            ]));
        }

        return implode("\n", $lines);
    }

    /**
     * One line, for the template (Meta allows no line breaks in values).
     *
     * @param  array<string, mixed>  $s
     */
    public static function line(array $s, string $locale, bool $money): string
    {
        $hotel = app(TenantContext::class)->hotel();

        return implode(', ', array_filter([
            trans_choice(':count arrival|:count arrivals', $s['arrivals'], [], $locale),
            trans_choice(':count departure|:count departures', $s['departures'], [], $locale),
            __('tonight :occupied/:rooms rooms (:percent%)', ['occupied' => $s['occupied'], 'rooms' => $s['rooms'], 'percent' => $s['percent']], $locale),
            $money ? __('collected yesterday :total', ['total' => MoneyFormatter::format(Money::ofMinor($s['collected'], $hotel->currency), $locale)], $locale) : null,
            $s['pending_requests'] > 0 ? trans_choice(':count WhatsApp request waiting|:count WhatsApp requests waiting', $s['pending_requests'], [], $locale) : null,
        ]));
    }

    /**
     * Deposits received yesterday by method (Sprint F); amounts only for
     * those who see the money. Refunds still to make.
     *
     * @param  array<string, mixed>  $s
     * @param  \Closure(int): string  $format
     * @return list<string>
     */
    private static function depositLines(array $s, string $locale, bool $money, \Closure $format): array
    {
        $lines = [];
        $deposits = $s['deposits'] ?? [];

        if ($deposits !== []) {
            $parts = [];
            foreach ($deposits as $method => $row) {
                $parts[] = RestaurantSettings::methodLabel((string) $method).' '.$row['count'];
            }
            $total = array_sum(array_column($deposits, 'minor'));
            $lines[] = '💳 '.__('Deposits received yesterday: :methods', ['methods' => implode(', ', $parts)], $locale).($money ? ' ('.$format($total).')' : '');
        }

        if (($s['refunds_due'] ?? 0) > 0) {
            $lines[] = '↩️ '.trans_choice(':count deposit to refund|:count deposits to refund', (int) $s['refunds_due'], [], $locale);
        }

        return $lines;
    }

    /**
     * Yesterday's shift checklists (Sprint E), quiet day or not.
     *
     * @param  array<string, mixed>  $s
     * @return list<string>
     */
    private static function checklistLines(array $s, string $locale): array
    {
        $lines = [];
        foreach ($s['checklists'] ?? [] as $list) {
            $lines[] = ($list['done'] === $list['total'] ? '✅ ' : '☑️ ').__('Yesterday, :name: :done/:total', ['name' => $list['name'], 'done' => $list['done'], 'total' => $list['total']], $locale)
                .($list['left'] === [] ? '' : ' ('.__('not done: :tasks', ['tasks' => implode(', ', array_slice($list['left'], 0, 3))], $locale).')');
        }

        return $lines;
    }

    /** ▓▓▓▓░ for 80 %. */
    public static function bar(int $percent): string
    {
        $full = (int) round(max(0, min(100, $percent)) / 20);

        return str_repeat('▓', $full).str_repeat('░', 5 - $full);
    }

    private static function points(int $diff, string $locale): string
    {
        return __(':diff points vs last week', ['diff' => ($diff > 0 ? '+' : '').$diff], $locale);
    }

    private static function change(int $now, int $before, string $locale): string
    {
        $percent = (int) round(100 * ($now - $before) / $before);

        return __(':diff% vs last week', ['diff' => ($percent > 0 ? '+' : '').$percent], $locale);
    }
}
