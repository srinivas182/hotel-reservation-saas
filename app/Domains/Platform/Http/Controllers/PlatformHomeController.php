<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Ai\Models\AiRun;
use App\Domains\Billing\Invoices\RevenueReport;
use App\Domains\Billing\Models\BillingEntity;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Message;
use App\Domains\Messaging\Usage\UsageRecord;
use App\Domains\Onboarding\Models\PendingSignup;
use App\Domains\Operations\OpsIncident;
use App\Domains\Platform\HotelHealth;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform home: what needs attention, today across all hotels, and the
 * business, filterable by country and issuing company.
 */
final class PlatformHomeController extends Controller
{
    public function __invoke(Request $request, HotelHealth $health): Response
    {
        $data = $request->validate(['country' => ['nullable', 'string', 'size:2'], 'entity' => ['nullable', 'integer']]);
        $hotels = Hotel::query()
            ->when(isset($data['country']), fn ($q) => $q->where('country_code', strtoupper((string) $data['country'])))
            ->when(isset($data['entity']), function ($q) use ($data): void {
                $ids = Subscription::query()->withoutGlobalScopes()->where('billing_entity_id', (int) $data['entity'])->pluck('hotel_id');
                $q->whereIn('id', $ids);
            })
            ->orderBy('name')->get();
        $ids = $hotels->pluck('id')->all();

        // Health is computed per hotel; cached a minute so the page stays fast.
        $key = 'platform-home:'.md5(json_encode($data) ?: '');
        $attention = Cache::remember($key, 60, function () use ($hotels, $health): array {
            $rows = [];
            foreach ($hotels as $hotel) {
                $h = $health->for($hotel);
                if ($h['issues'] !== []) {
                    $rows[] = ['hotel_id' => $hotel->id, 'hotel' => $hotel->name, 'country' => $hotel->country_code, 'issues' => array_map(fn (string $i): array => ['key' => $i, 'label' => HotelHealth::issueLabel($i)], $h['issues'])];
                }
            }

            return $rows;
        });

        $today = CarbonImmutable::now()->startOfDay();
        $report = RevenueReport::build();

        return Inertia::render('platform/home', [
            'filters' => ['country' => $data['country'] ?? null, 'entity' => $data['entity'] ?? null],
            'countries' => Hotel::query()->distinct()->orderBy('country_code')->pluck('country_code')->values(),
            'entities' => BillingEntity::query()->get(['id', 'name'])->values(),
            'attention' => $attention,
            'incidents' => OpsIncident::query()->whereNull('resolved_at')->orderByDesc('opened_at')->get(['id', 'title', 'opened_at'])->map(fn (OpsIncident $i): array => ['id' => $i->id, 'title' => $i->title, 'since' => $i->opened_at->toDateTimeString()])->values(),
            'today' => [
                'messages_in' => Message::query()->withoutGlobalScopes()->whereIn('hotel_id', $ids)->where('direction', 'in')->where('created_at', '>=', $today)->count(),
                'messages_out' => Message::query()->withoutGlobalScopes()->whereIn('hotel_id', $ids)->where('direction', 'out')->where('created_at', '>=', $today)->count(),
                'bookings' => Booking::query()->withoutGlobalScopes()->whereIn('hotel_id', $ids)->where('created_at', '>=', $today)->count(),
                'ai_runs' => AiRun::query()->withoutGlobalScopes()->whereIn('hotel_id', $ids)->where('created_at', '>=', $today)->count(),
                'ai_cost_usd' => number_format(((int) UsageRecord::query()->withoutGlobalScopes()->whereIn('hotel_id', $ids)->where('meter', 'ai.cost_micro_usd')->where('occurred_at', '>=', $today)->sum('quantity')) / 1_000_000, 2),
            ],
            'business' => [
                'hotels' => count($ids),
                'live' => \App\Domains\Ai\Models\HotelAssistantSettings::query()->withoutGlobalScopes()->whereIn('hotel_id', $ids)->where('mode', '!=', 'off')->count(),
                'trials_ending' => Subscription::query()->withoutGlobalScopes()->whereIn('hotel_id', $ids)->where('status', 'trialing')->where('period_end', '<=', now()->addDays(7))->count(),
                'signups_week' => PendingSignup::query()->where('created_at', '>=', now()->subDays(7))->count(),
                'mrr' => $report['mrr'],
                'overdue' => $report['overdue'],
            ],
        ]);
    }
}
