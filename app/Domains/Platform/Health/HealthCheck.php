<?php

declare(strict_types=1);

namespace App\Domains\Platform\Health;

interface HealthCheck
{
    public function name(): string;

    /**
     * @return array{ok: bool, detail?: string}
     */
    public function run(): array;
}
