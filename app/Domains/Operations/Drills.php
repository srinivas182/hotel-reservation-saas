<?php

declare(strict_types=1);

namespace App\Domains\Operations;

use Illuminate\Support\Facades\DB;

/**
 * S16.4 drills on staging: recorded by scripts/drills/staging-drills.sh
 * through `php artisan drills:record`, read by the launch checklist.
 */
final class Drills
{
    /** Drills that must all have passed (last 30 days) before launch. */
    public const REQUIRED = [
        'load_full' => 'Full-size load test (200 hotels, 20 messages/s)',
        'workers_stop' => 'Background workers stop and restart',
        'redis_restart' => 'Redis restarts',
        'mysql_restart' => 'MySQL restarts',
        'ai_down' => 'AI providers down',
        'meta_errors' => 'Meta errors on send',
        'mail_down' => 'Email server unreachable',
        'duplicate_webhooks' => 'Duplicate notifications',
        'restore' => 'Backup restore drill',
        'rollback' => 'Deploy and rollback',
    ];

    /**
     * @param  array<string, string>  $measures
     */
    public static function record(string $drill, bool $pass, string $environment, ?string $notes = null, array $measures = []): void
    {
        DB::table('drill_runs')->insert([
            'drill' => $drill, 'result' => $pass ? 'pass' : 'fail', 'environment' => $environment,
            'notes' => $notes, 'measures' => $measures === [] ? null : json_encode($measures), 'created_at' => now(),
        ]);
    }

    /**
     * Latest result per required drill in the last 30 days.
     *
     * @return array<string, array{result: string|null, at: string|null}>
     */
    public static function status(): array
    {
        $status = [];
        foreach (array_keys(self::REQUIRED) as $drill) {
            $last = DB::table('drill_runs')->where('drill', $drill)->where('environment', '!=', 'testing')->where('created_at', '>=', now()->subDays(30))->orderByDesc('id')->first();
            $status[$drill] = ['result' => $last?->result, 'at' => $last?->created_at];
        }

        return $status;
    }

    public static function allPassed(): bool
    {
        return collect(self::status())->every(fn (array $s): bool => $s['result'] === 'pass');
    }
}
