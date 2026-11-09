<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Operations\Drills;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('drills:record {drill} {result : pass or fail} {--notes=} {--measure=* : key=value}')]
#[Description('Record the result of a staging drill (used by scripts/drills/staging-drills.sh)')]
final class RecordDrill extends Command
{
    public function handle(): int
    {
        $drill = (string) $this->argument('drill');
        $result = (string) $this->argument('result');

        if (! array_key_exists($drill, Drills::REQUIRED) || ! in_array($result, ['pass', 'fail'], true)) {
            $this->components->error('Unknown drill or result. Drills: '.implode(', ', array_keys(Drills::REQUIRED)));

            return self::INVALID;
        }

        $measures = [];
        foreach ((array) $this->option('measure') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, '');
            $measures[$key] = $value;
        }

        Drills::record($drill, $result === 'pass', (string) app()->environment(), $this->option('notes') === null ? null : (string) $this->option('notes'), $measures);
        $this->components->info("{$drill}: {$result}");

        return self::SUCCESS;
    }
}
