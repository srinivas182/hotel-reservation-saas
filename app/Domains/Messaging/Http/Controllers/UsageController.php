<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Http\Controllers;

use App\Domains\Messaging\Email\EmailCategory;
use App\Domains\Messaging\Email\Models\EmailMessage;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email usage for the current hotel: this month by category, failures,
 * and the last 30 days. Limits are shown from S13 (packages and credits).
 */
final class UsageController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(): Response
    {
        $hotel = $this->context->hotel();
        $now = now($hotel->timezone);
        $monthStart = $now->startOfMonth()->utc();
        $windowStart = $now->subDays(29)->startOfDay()->utc();

        $base = fn () => EmailMessage::query()->where('hotel_id', $hotel->getKey());

        $thisMonth = $base()
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('category, status, count(*) as total')
            ->groupBy('category', 'status')
            ->get();

        $categories = collect(EmailCategory::cases())->map(fn (EmailCategory $category): array => [
            'category' => $category->value,
            'label' => $category->label(),
            'counted' => $category->countable(),
            'sent' => (int) $thisMonth->where('category', $category)->where('status', EmailMessage::STATUS_SENT)->sum('total'),
            'failed' => (int) $thisMonth->where('category', $category)->where('status', EmailMessage::STATUS_FAILED)->sum('total'),
        ])->values();

        $daily = $base()
            ->where('created_at', '>=', $windowStart)
            ->where('status', EmailMessage::STATUS_SENT)
            ->get(['created_at'])
            ->groupBy(fn (EmailMessage $message): string => (string) $message->created_at?->setTimezone($hotel->timezone)->toDateString())
            ->map->count();

        $days = collect(range(29, 0))->map(function (int $back) use ($now, $daily): array {
            $date = $now->subDays($back)->toDateString();

            return ['date' => $date, 'sent' => (int) ($daily[$date] ?? 0)];
        })->values();

        return Inertia::render('hotel/usage', [
            'categories' => $categories,
            'days' => $days,
            'limit' => null,
            'month' => $this->monthLabel($now, $hotel->default_locale),
        ]);
    }

    private function monthLabel(\Carbon\CarbonImmutable $now, string $locale): string
    {
        /** @var \Carbon\CarbonImmutable $localized */
        $localized = $now->locale($locale);

        return $localized->isoFormat('MMMM YYYY');
    }
}
