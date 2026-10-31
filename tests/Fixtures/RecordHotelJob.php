<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Records which hotel was current while the job ran.
 */
class RecordHotelJob implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    public function handle(TenantContext $context): void
    {
        Cache::put('fixture.job_hotel', $context->idOrNull());
        Cache::put('fixture.job_note_count', Note::query()->count());
    }
}
