<?php

declare(strict_types=1);

namespace App\Domains\Launch;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * J: launch-week watch — hour by hour over the last days: sign-ups, new
 * hotels, guest messages, failed sends, failed jobs, support requests,
 * payments. Counts only; no personal data.
 */
final class LaunchWatch
{
    /**
     * @return array{hours: list<string>, series: array<string, list<int>>, totals: array<string, int>}
     */
    public static function build(int $days = 7): array
    {
        $end = CarbonImmutable::now()->startOfHour()->addHour();
        $start = $end->subDays($days);
        $hours = [];
        for ($h = $start; $h < $end; $h = $h->addHour()) {
            $hours[] = $h->format('Y-m-d H:00');
        }

        $count = function (string $table, string $column, ?\Closure $where = null) use ($start, $hours): array {
            $query = DB::table($table)->where($column, '>=', $start);
            if ($where !== null) {
                $where($query);
            }
            $byHour = [];
            foreach ($query->pluck($column) as $at) {
                $key = CarbonImmutable::parse((string) $at)->format('Y-m-d H:00');
                $byHour[$key] = ($byHour[$key] ?? 0) + 1;
            }

            return array_map(fn (string $hour): int => $byHour[$hour] ?? 0, $hours);
        };

        $series = [
            'signups' => $count('pending_signups', 'created_at'),
            'hotels' => $count('hotels', 'created_at', fn ($q) => $q->where('is_demo', false)),
            'messages_in' => $count('messages', 'created_at', fn ($q) => $q->where('direction', 'in')),
            'send_failures' => $count('messages', 'updated_at', fn ($q) => $q->where('direction', 'out')->where('status', 'failed')),
            'failed_jobs' => $count('failed_jobs', 'failed_at'),
            'support' => $count('support_tickets', 'created_at'),
            'payments' => $count('invoice_payments', 'created_at'),
        ];

        return ['hours' => $hours, 'series' => $series, 'totals' => array_map(fn (array $values): int => array_sum($values), $series)];
    }
}
