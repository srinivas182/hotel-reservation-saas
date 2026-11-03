<?php

declare(strict_types=1);

namespace App\Domains\Ai\Gateway;

use App\Domains\Ai\AiTask;
use Laravel\Ai\Contracts\Agent;

/**
 * The only way the platform talks to AI providers. Wraps laravel/ai so the
 * rest of the code never depends on it directly.
 */
interface AiGateway
{
    /**
     * Prompt an agent for the current hotel: checks the off switches and the
     * spending limit, tries the task's main model then its backup, and
     * records every attempt.
     *
     * @throws \App\Domains\Ai\Exceptions\AiUnavailable
     */
    public function prompt(AiTask $task, Agent $agent, string $prompt, ?string $conversationId = null): AiResult;
}
