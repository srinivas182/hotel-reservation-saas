<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine;

use Brick\Money\Money;

/**
 * Shared by the assistant's tools during one reply: the amounts the tools
 * returned (the only amounts the reply may state) and a log of calls.
 */
final class ToolContext
{
    /** @var array<string, true> normalised amounts */
    private array $amounts = [];

    /** @var list<array{tool: string, arguments: array<string, mixed>, result: string}> */
    private array $calls = [];

    public function __construct(public readonly string $locale) {}

    public function allowMoney(Money $money): void
    {
        $this->amounts[Amounts::normalise((string) $money->getAmount())] = true;
    }

    public function allowsAmount(string $normalised): bool
    {
        return isset($this->amounts[$normalised]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function log(string $tool, array $arguments, string $result): void
    {
        $this->calls[] = ['tool' => $tool, 'arguments' => $arguments, 'result' => mb_substr($result, 0, 2000)];
    }

    /**
     * @return list<array{tool: string, arguments: array<string, mixed>, result: string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function used(string $tool): bool
    {
        foreach ($this->calls as $call) {
            if ($call['tool'] === $tool) {
                return true;
            }
        }

        return false;
    }
}
