<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine;

/**
 * What the assistant decided for one guest message.
 */
final readonly class AssistantResult
{
    /**
     * @param  list<array{tool: string, arguments: array<string, mixed>, result: string}>  $tools
     * @param  list<array{check: string, passed: bool, detail: string|null}>  $guardrails
     * @param  list<int>  $runIds
     */
    public function __construct(
        public string $reply,
        public string $language,
        public string $intent,
        public bool $handover,
        public ?string $handoverReason,
        public float $confidence,
        public array $tools = [],
        public array $guardrails = [],
        public ?string $model = null,
        public int $latencyMs = 0,
        public int $costMicroUsd = 0,
        public int $attempts = 0,
        public array $runIds = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
