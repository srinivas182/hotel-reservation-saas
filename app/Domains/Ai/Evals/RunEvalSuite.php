<?php

declare(strict_types=1);

namespace App\Domains\Ai\Evals;

use App\Domains\Ai\Models\AiModel;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the test suite in the background (started from Platform → AI).
 */
final class RunEvalSuite implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public readonly int $modelId, public readonly ?string $userId, public readonly bool $approve) {}

    public function handle(EvalRunner $runner): void
    {
        $model = AiModel::query()->find($this->modelId);

        if ($model !== null) {
            $runner->run($model, by: $this->userId === null ? null : User::query()->find($this->userId), approve: $this->approve);
        }
    }
}
