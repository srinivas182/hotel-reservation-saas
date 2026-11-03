<?php

declare(strict_types=1);

namespace App\Domains\Ai\Gateway;

use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiRun;
use Laravel\Ai\Responses\AgentResponse;

/**
 * A successful AI call: the library's response plus which model answered
 * and the recorded run.
 */
final readonly class AiResult
{
    /**
     * @param  list<string>  $failedModels  models that failed before this one answered
     */
    public function __construct(
        public AgentResponse $response,
        public AiModel $model,
        public AiRun $run,
        public array $failedModels = [],
    ) {}
}
