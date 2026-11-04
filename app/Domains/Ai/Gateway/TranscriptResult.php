<?php

declare(strict_types=1);

namespace App\Domains\Ai\Gateway;

use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiRun;

final readonly class TranscriptResult
{
    /**
     * @param  list<string>  $failedModels
     */
    public function __construct(
        public string $text,
        public AiModel $model,
        public AiRun $run,
        public array $failedModels = [],
    ) {}
}
