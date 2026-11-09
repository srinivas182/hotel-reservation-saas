<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Launch\LaunchChecklist;
use App\Domains\Launch\Models\LaunchItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Used before releasing 1.0.0 (deploy.sh refuses 1.x otherwise): fails while
 * a required launch item is open.
 */
#[Signature('launch:check')]
#[Description('Fail unless every required launch checklist item is done')]
final class LaunchCheck extends Command
{
    public function handle(LaunchChecklist $checklist): int
    {
        $score = $checklist->refresh();
        $open = LaunchItem::query()->where('required', true)->whereNotIn('status', ['done', 'not_needed'])->orderBy('sort_order')->get();

        foreach ($open as $item) {
            $this->line(' - '.($item->title['en'] ?? $item->key)." ({$item->owner_role})");
        }

        if (! $score['ready']) {
            $this->components->error("Launch readiness {$score['score']}% ({$score['done']}/{$score['required']}). Not ready for 1.0.0.");

            return self::FAILURE;
        }

        $this->components->info('Every required item is done: ready for 1.0.0.');

        return self::SUCCESS;
    }
}
