<?php

declare(strict_types=1);

namespace App\Domains\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * A tiny prompt used to check a model answers (platform admin "Test").
 */
final class PingAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Reply with the single word OK.';
    }
}
