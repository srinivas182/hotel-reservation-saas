<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine;

/**
 * One message of the conversation given to the assistant.
 */
final readonly class AssistantTurn
{
    public function __construct(
        public string $role, // guest or hotel
        public string $text,
    ) {}

    public static function guest(string $text): self
    {
        return new self('guest', $text);
    }

    public static function hotel(string $text): self
    {
        return new self('hotel', $text);
    }
}
