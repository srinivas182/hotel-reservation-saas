<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Demo\DemoHotels;
use App\Domains\Platform\Lifecycle\HotelTables;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('demo:maintain {--reset : Reset the permanent demo hotel to its starting point}')]
#[Description('Delete expired private demos; with --reset, reset the sales demo hotel')]
final class DemoMaintenance extends Command
{
    public function handle(DemoHotels $demos): int
    {
        $deleted = 0;
        foreach (Hotel::query()->where('is_demo', true)->whereNotNull('demo_expires_at')->where('demo_expires_at', '<=', now())->where('status', '!=', HotelStatus::Closed->value)->get() as $hotel) {
            $members = $hotel->members()->pluck('users.id')->all();
            foreach (HotelTables::deletionOrder() as [$table, $via]) {
                $via === null
                    ? DB::table($table)->where('hotel_id', $hotel->id)->delete()
                    : DB::table($table)->whereIn($via['column'], DB::table($via['parent'])->where('hotel_id', $hotel->id)->select($via['parent_column']))->delete();
            }
            User::query()->whereIn('id', $members)->whereNull('platform_role')->delete();
            $hotel->forceFill(['status' => HotelStatus::Closed, 'name' => 'Expired demo '.substr($hotel->id, -6)])->save();
            $deleted++;
        }

        if ($this->option('reset') && ($demo = DemoHotels::permanent()) !== null) {
            $demos->reset($demo);
            $this->components->info('Demo hotel reset.');
        }

        $this->components->info("{$deleted} expired private demo(s) deleted.");

        return self::SUCCESS;
    }
}
