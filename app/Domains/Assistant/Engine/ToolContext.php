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

    /**
     * @param  string|null  $conversationId  null in the practice screen, where nothing is created
     */
    public function __construct(
        public readonly string $locale,
        public readonly ?string $conversationId = null,
    ) {}

    public function isPractice(): bool
    {
        return $this->conversationId === null;
    }

    public function allowMoney(Money $money): void
    {
        $this->amounts[Amounts::normalise((string) $money->getAmount())] = true;
    }

    /**
     * Amounts the hotel already wrote to this guest (an earlier assistant
     * reply that passed these checks, or a staff message) may be repeated:
     * "the Suite at 60 000 XOF" stays allowed when the guest then says yes.
     * Amounts written by the guest are never allowed this way.
     */
    public function allowAmountsIn(string $hotelText): void
    {
        foreach (Amounts::find($hotelText) as $amount) {
            $this->amounts[$amount['normalised']] = true;
        }
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
