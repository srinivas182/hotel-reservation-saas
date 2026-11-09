<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\Models\Plan;
use App\Domains\Site\Models\BrandSettings;
use App\Domains\Support\Models\SupportSettings;
use Database\Seeders\BillingCatalogSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Applies the launch defaults once, without overwriting anything the
 * platform has already set (safe to run again).
 */
#[Signature('launch:defaults')]
#[Description('Apply launch defaults: plans and prices, support hours and targets (never overwrites existing choices)')]
final class ApplyLaunchDefaults extends Command
{
    public function handle(): int
    {
        if (Plan::query()->doesntExist()) {
            $this->call('db:seed', ['--class' => BillingCatalogSeeder::class, '--force' => true]);
        }

        $added = 0;
        foreach (config('launch.extra_prices') as $code => $prices) {
            $plan = Plan::query()->where('code', $code)->first();
            if ($plan === null) {
                continue;
            }
            $current = $plan->prices;
            foreach ($prices as $currency => [$monthly, $yearly]) {
                if (! isset($current[$currency])) {
                    $unit = (int) (config('launch.minor_units')[$currency] ?? 100);
                    $current[$currency] = ['monthly' => $monthly * $unit, 'yearly' => $yearly * $unit];
                    $added++;
                }
            }
            $plan->update(['prices' => $current]);
        }

        $support = SupportSettings::current();
        // Only the earlier built-in values are replaced (MySQL reorders JSON keys, so compare sorted).
        $current = $support->response_hours;
        ksort($current);
        if ($current === ['default' => 24] || $current === ['complet' => 8, 'default' => 24, 'pro' => 8]) {
            $support->update(['response_hours' => config('launch.support_response_hours')]);
        }

        $brand = BrandSettings::current();
        if ($brand->support_hours === null) {
            $brand->update(['support_hours' => config('launch.support_hours')]);
        }

        $this->components->info("Launch defaults applied ({$added} price(s) added).");

        return self::SUCCESS;
    }
}
