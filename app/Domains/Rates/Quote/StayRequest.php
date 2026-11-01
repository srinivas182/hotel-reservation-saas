<?php

declare(strict_types=1);

namespace App\Domains\Rates\Quote;

use Carbon\CarbonImmutable;

/**
 * A stay to price. Children are given as ages; null ages are used when the
 * hotel has no age bands (every child then pays the child rate).
 */
final readonly class StayRequest
{
    /**
     * @param  list<int|null>  $childAges
     */
    public function __construct(
        public string $categoryId,
        public CarbonImmutable $checkIn,
        public CarbonImmutable $checkOut,
        public int $adults,
        public array $childAges = [],
        public int $rooms = 1,
        public bool $breakfast = false,
        public bool $extraBed = false,
    ) {}

    public function nights(): int
    {
        return (int) $this->checkIn->diffInDays($this->checkOut, false);
    }
}
