<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Availability\HoldStatus;
use App\Domains\Availability\Models\InventoryDay;
use App\Domains\Availability\Models\InventoryHold;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalculate booked counts from their source records: bookings that
 * occupy rooms (confirmed, checked in, checked out).
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
                $add = function (string $categoryId, CarbonInterface $checkIn, CarbonInterface $checkOut, int $rooms) use (&$counts): void {
                    foreach (CarbonPeriod::create($checkIn, $checkOut->subDay()) as $night) {
                        $key = $categoryId.'|'.$night->toDateString();
                        $counts[$key] = ($counts[$key] ?? 0) + $rooms;
                    }
                };

                // Bookings that occupy their rooms.
                Booking::query()
                    ->whereIn('status', array_map(fn (BookingStatus $status): string => $status->value, BookingStatus::occupying()))
                    ->get(['room_category_id', 'check_in', 'check_out', 'rooms'])
                    ->each(fn (Booking $booking) => $add($booking->room_category_id, $booking->check_in, $booking->check_out, $booking->rooms));

                // Holds converted outside a booking (none are created by the app today).
                InventoryHold::query()
                    ->where('status', HoldStatus::Converted->value)
                    ->where('source_type', '!=', 'booking')
                    ->get()
                    ->each(fn (InventoryHold $hold) => $add($hold->room_category_id, $hold->check_in, $hold->check_out, $hold->rooms));

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
