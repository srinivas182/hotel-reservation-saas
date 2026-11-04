<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Ai\Evals\EvalRunner;
use App\Domains\Ai\Models\AiModel;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:eval {model : AI model ID or model name} {--suite= : Path to a test suite (JSON)} {--case=* : Only these case IDs} {--approve : Approve the model if it meets the threshold}')]
#[Description('Run the assistant test suite against a real AI model (costs money; never runs in CI)')]
final class RunAiEval extends Command
{
    public function handle(EvalRunner $runner): int
    {
        $key = (string) $this->argument('model');
        $model = AiModel::query()->whereKey(ctype_digit($key) ? (int) $key : 0)->orWhere('model', $key)->first();

        if ($model === null) {
            $this->components->error("No AI model \"{$key}\".");

            return self::FAILURE;
        }

        $this->components->info("Running the test suite against {$model->label} ({$model->model})…");

        $run = $runner->run(
            $model,
            is_string($this->option('suite')) ? $this->option('suite') : null,
            array_values(array_map('strval', (array) $this->option('case'))),
            approve: (bool) $this->option('approve'),
            progress: fn (string $id, bool $passed) => $this->line(($passed ? ' <fg=green>✓</>' : ' <fg=red>✗</>').' '.$id),
        );

        $this->newLine();
        $this->components->twoColumnDetail('Passed', "{$run->passed}/{$run->total} (".round($run->score() * 100, 1).'%)');
        $this->components->twoColumnDetail('Safety cases', "{$run->safety_passed}/{$run->safety_total}");
        $this->components->twoColumnDetail('Cost', 'US$ '.number_format($run->cost_micro_usd / 1_000_000, 4));
        $this->components->twoColumnDetail('Meets the approval threshold', $run->meets_threshold ? 'yes' : 'no');

        foreach ((array) $run->results as $result) {
            if (! $result['passed']) {
                $this->line(" <fg=red>{$result['id']}</>: ".implode(' ', (array) $result['failures']));
            }
        }

        return $run->meets_threshold ? self::SUCCESS : self::FAILURE;
    }
}
