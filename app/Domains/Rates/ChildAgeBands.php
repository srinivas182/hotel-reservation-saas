<?php

declare(strict_types=1);

namespace App\Domains\Rates;

/**
 * The hotel's child age bands, for example 0–5 free, 6–11 child rate.
 * Ages above the last band (or any age when no bands are set) follow the
 * default: children are charged the child rate.
 */
final readonly class ChildAgeBands
{
    /**
     * @param  list<array{from: int, to: int, charge: string}>  $bands
     */
    public function __construct(public array $bands) {}

    /**
     * @param  mixed  $value  stored JSON value
     */
    public static function fromSettings(mixed $value): self
    {
        $bands = [];

        foreach (is_array($value) ? $value : [] as $band) {
            if (is_array($band) && isset($band['from'], $band['to'], $band['charge'])) {
                $bands[] = ['from' => (int) $band['from'], 'to' => (int) $band['to'], 'charge' => (string) $band['charge']];
            }
        }

        return new self($bands);
    }

    public function isEmpty(): bool
    {
        return $this->bands === [];
    }

    public function chargeFor(?int $age): ChildCharge
    {
        if ($age === null) {
            return ChildCharge::ChildRate;
        }

        foreach ($this->bands as $band) {
            if ($age >= $band['from'] && $age <= $band['to']) {
                return ChildCharge::tryFrom($band['charge']) ?? ChildCharge::ChildRate;
            }
        }

        return $this->bands === [] ? ChildCharge::ChildRate : ChildCharge::Adult;
    }
}
