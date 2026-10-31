<?php

declare(strict_types=1);

namespace App\Domains\Platform\Health;

use Illuminate\Support\Facades\DB;
use Throwable;

final class DatabaseCheck implements HealthCheck
{
    public function name(): string
    {
        return 'database';
    }

    public function run(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true];
        } catch (Throwable) {
            return ['ok' => false, 'detail' => 'Database is unreachable.'];
        }
    }
}
