<?php

declare(strict_types=1);

namespace App\Domains\Availability;

/**
 * Who is selling right now (0.36.7). The WhatsApp assistant does not see
 * the rooms a hotel keeps for reception; staff, the calendar and every
 * other path see all rooms. One instance per request or job.
 */
final class SalesChannel
{
    private int $assistant = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function asAssistant(callable $callback): mixed
    {
        $this->assistant++;

        try {
            return $callback();
        } finally {
            $this->assistant--;
        }
    }

    public function isAssistant(): bool
    {
        return $this->assistant > 0;
    }
}
