<?php

declare(strict_types=1);

namespace App\Domains\Status;

use App\Domains\Operations\OpsIncident;
use App\Domains\Status\Models\StatusNotice;
use Illuminate\Support\Facades\Cache;

/**
 * The state of each service: from the operations checks' open incidents
 * (automatic) and from the notices the team posts (manual).
 */
final class StatusBoard
{
    /** Which operations checks speak for which public service. */
    private const FROM_OPS = [
        'queue_backlog' => ['platform', 'degraded'], 'queue_stopped' => ['platform', 'degraded'], 'scheduler_stopped' => ['platform', 'degraded'],
        'webhook_silence' => ['whatsapp', 'degraded'], 'webhook_failures' => ['whatsapp', 'degraded'], 'whatsapp_failures' => ['whatsapp', 'degraded'],
        'ai_failures' => ['ai', 'degraded'],
    ];

    private const RANK = ['operational' => 0, 'maintenance' => 1, 'degraded' => 2, 'outage' => 3];

    /**
     * @return array<string, string> component => operational, maintenance, degraded, outage
     */
    public static function components(?string $country = null): array
    {
        $states = Cache::remember('status-board-ops', 60, function (): array {
            $states = array_fill_keys(StatusNotice::COMPONENTS, 'operational');
            foreach (OpsIncident::query()->whereNull('resolved_at')->pluck('key') as $key) {
                if (isset(self::FROM_OPS[$key])) {
                    [$component, $level] = self::FROM_OPS[$key];
                    $states[$component] = self::worse($states[$component], $level);
                }
            }

            return $states;
        });

        foreach (StatusNotice::query()->active()->get() as $notice) {
            if (! $notice->concerns($country)) {
                continue;
            }
            foreach ($notice->components as $component) {
                $states[$component] = self::worse($states[$component] ?? 'operational', $notice->level);
            }
        }

        return $states;
    }

    public static function overall(?string $country = null): string
    {
        return array_reduce(array_values(self::components($country)), fn (string $carry, string $s): string => self::worse($carry, $s), 'operational');
    }

    private static function worse(string $a, string $b): string
    {
        return self::RANK[$b] > self::RANK[$a] ? $b : $a;
    }
}
