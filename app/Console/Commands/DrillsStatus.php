<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Operations\Drills;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('drills:status')]
#[Description('Show the staging drills and the Content Security Policy reports (S16.4)')]
final class DrillsStatus extends Command
{
    public function handle(): int
    {
        $rows = [];
        foreach (Drills::status() as $drill => $s) {
            $rows[] = [$drill, Drills::REQUIRED[$drill], $s['result'] ?? '—', $s['at'] ?? '—'];
        }
        $this->table(['Drill', 'What', 'Result', 'When'], $rows);

        $csp = DB::table('csp_reports')->where('last_seen_at', '>=', now()->subDays(7))->orderByDesc('count')->limit(20)->get();
        $this->line('');
        $this->line('Content Security Policy reports, last 7 days: '.$csp->sum('count'));
        if ($csp->isNotEmpty()) {
            $this->table(['Directive', 'Blocked', 'Count', 'First page'], $csp->map(fn ($r): array => [$r->directive, $r->blocked, $r->count, $r->page])->all());
        }

        return Drills::allPassed() ? self::SUCCESS : self::FAILURE;
    }
}
