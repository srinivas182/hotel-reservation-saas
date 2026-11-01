<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Availability\HoldStatus;
use App\Domains\Availability\Models\InventoryDay;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalculate booked counts from their source records. Until bookings
 * arrive in S3 the source is converted holds; S3 switches it to bookings.
 * Held-back and stop-sell values are settings, not counts, and are kept.
 */
#[Signature('inventory:rebuild {--hotel= : Only this hotel (ID or slug)}')]
#[Description('Recalculate booked room counts from bookings')]
final class RebuildInventory extends Command
{
    public function handle(TenantContext $context): int
    {
        $only = $this->option('hotel');

        $context->eachHotel(function (Hotel $hotel) use ($only): void {
            if (is_string($only) && $only !== '' && $hotel->id !== $only && $hotel->slug !== $only) {
                return;
            }

            DB::transaction(function () use ($hotel): void {
                $counts = [];
                InventoryHold::query()->where('status', HoldStatus::Converted->value)->get()->each(function (InventoryHold $hold) use (&$counts): void {
                    foreach (CarbonPeriod::create($hold->check_in, $hold->check_out->subDay()) as $night) {
                        $key = $hold->room_category_id.'|'.$night->toDateString();
                        $counts[$key] = ($counts[$key] ?? 0) + $hold->rooms;
                    }
                });

                $changed = 0;
                InventoryDay::query()->lockForUpdate()->get()->each(function (InventoryDay $day) use (&$counts, &$changed): void {
                    $key = $day->room_category_id.'|'.$day->date->toDateString();
                    $booked = $counts[$key] ?? 0;
                    unset($counts[$key]);

                    if ($day->booked !== $booked) {
                        $day->forceFill(['booked' => $booked])->save();
                        $changed++;
                    }
                });

                foreach ($counts as $key => $booked) {
                    [$categoryId, $date] = explode('|', $key);
                    InventoryDay::query()->create(['room_category_id' => $categoryId, 'date' => $date, 'booked' => $booked]);
                    $changed++;
                }

                $this->components->info("{$hotel->name}: {$changed} night(s) corrected.");
            });
        });

        return self::SUCCESS;
    }
}
