<?php

declare(strict_types=1);

namespace App\Domains\Availability;

use App\Domains\Audit\Audit;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calendar range actions: stop-sell on or off, and rooms held back from
 * sale, for one or more categories over a range of nights.
 */
final class InventoryControls
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly HoldService $holds,
    ) {}

    /**
     * @param  list<string>  $categoryIds
     */
    public function setStopSell(array $categoryIds, CarbonImmutable $from, CarbonImmutable $to, bool $stopSell, ?User $by = null): void
    {
        $this->apply($categoryIds, $from, $to, ['stop_sell' => $stopSell]);

        Audit::record('availability.stop_sell', $stopSell ? 'Stop-sell set' : 'Stop-sell removed', subject: $this->context->hotel(), causer: $by, properties: [
            'categories' => $categoryIds, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'stop_sell' => $stopSell,
        ]);
    }

    /**
     * @param  list<string>  $categoryIds
     */
    public function setHeldBack(array $categoryIds, CarbonImmutable $from, CarbonImmutable $to, int $rooms, ?User $by = null): void
    {
        $this->apply($categoryIds, $from, $to, ['held_back' => max(0, $rooms)]);

        Audit::record('availability.held_back', 'Rooms held back from sale', subject: $this->context->hotel(), causer: $by, properties: [
            'categories' => $categoryIds, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'rooms' => $rooms,
        ]);
    }

    /**
     * @param  list<string>  $categoryIds
     * @param  array<string, mixed>  $values
     */
    private function apply(array $categoryIds, CarbonImmutable $from, CarbonImmutable $to, array $values): void
    {
        DB::transaction(function () use ($categoryIds, $from, $to, $values): void {
            foreach ($categoryIds as $categoryId) {
                foreach ($this->holds->lockNights($categoryId, $from, $to->addDay()) as $day) {
                    $day->forceFill($values)->save();
                }
            }
        });
    }
}
