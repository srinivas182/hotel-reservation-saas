<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Billing\Models\CreditPack;
use App\Domains\Billing\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Example plans and credit packs (prices are placeholders to change in
 * Platform → Billing). Safe to run again: existing codes are left alone.
 */
final class BillingCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['essentiel', ['fr' => 'Essentiel', 'en' => 'Essential'], [], 5, 1000, 500, ['XOF' => [25000, 250000], 'EUR' => [39, 390], 'USD' => [42, 420]], 1],
            ['pro', ['fr' => 'Pro', 'en' => 'Pro'], ['restaurant', 'ordering'], 15, 3000, 2000, ['XOF' => [45000, 450000], 'EUR' => [69, 690], 'USD' => [75, 750]], 2],
            ['complet', ['fr' => 'Complet', 'en' => 'Complete'], ['restaurant', 'ordering', 'events'], null, 8000, 5000, ['XOF' => [75000, 750000], 'EUR' => [115, 1150], 'USD' => [125, 1250]], 3],
        ];

        foreach ($plans as [$code, $name, $modules, $staff, $ai, $email, $prices, $sort]) {
            Plan::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'modules' => $modules, 'staff_limit' => $staff, 'ai_credits' => $ai, 'email_credits' => $email,
                // EUR and USD in cents.
                'prices' => array_map(fn (array $p): array => ['monthly' => $p[0], 'yearly' => $p[1]], ['XOF' => $prices['XOF'], 'EUR' => [$prices['EUR'][0] * 100, $prices['EUR'][1] * 100], 'USD' => [$prices['USD'][0] * 100, $prices['USD'][1] * 100]]),
                'trial_days' => 14, 'is_default' => $code === 'pro', 'active' => true, 'sort_order' => $sort,
            ]);
        }

        foreach ([['ai-1000', 'ai', 1000, ['XOF' => 10000, 'EUR' => 1500]], ['ai-5000', 'ai', 5000, ['XOF' => 40000, 'EUR' => 6000]], ['email-2000', 'email', 2000, ['XOF' => 5000, 'EUR' => 800]]] as [$code, $kind, $credits, $prices]) {
            CreditPack::query()->firstOrCreate(['code' => $code], [
                'name' => ['fr' => "{$credits} crédits ".($kind === 'ai' ? 'IA' : 'e-mail'), 'en' => "{$credits} ".($kind === 'ai' ? 'AI' : 'email').' credits'],
                'kind' => $kind, 'credits' => $credits, 'prices' => $prices, 'valid_months' => 12, 'active' => true,
            ]);
        }
    }
}
