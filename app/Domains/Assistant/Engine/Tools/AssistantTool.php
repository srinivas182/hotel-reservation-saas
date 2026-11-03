<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Assistant\Engine\ToolContext;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Base for the assistant's read-only tools. Results are JSON and every
 * call is logged in the tool context.
 */
abstract class AssistantTool implements Tool
{
    public function __construct(protected readonly ToolContext $context) {}

    abstract public function name(): string;

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    abstract protected function run(array $arguments): array;

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->all();
        $result = json_encode($this->run($arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $this->context->log($this->name(), $arguments, $result);

        return $result;
    }
}
