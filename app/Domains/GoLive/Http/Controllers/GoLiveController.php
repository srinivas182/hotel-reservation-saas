<?php

declare(strict_types=1);

namespace App\Domains\GoLive\Http\Controllers;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Conversations\Models\Message;
use App\Domains\GoLive\Readiness;
use App\Domains\GoLive\ReadinessCheck;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The hotel's go-live readiness page.
 */
final class GoLiveController extends Controller
{
    public function __invoke(Readiness $readiness, TenantContext $context): Response
    {
        $settings = HotelAssistantSettings::current();
        $checks = $readiness->checks();

        $outcomes = Message::query()->whereNotNull('draft_outcome')->selectRaw('draft_outcome, count(*) as total')->groupBy('draft_outcome')->pluck('total', 'draft_outcome');
        $handled = (int) $outcomes->sum();

        return Inertia::render('hotel/go-live', [
            'checks' => array_map(fn (ReadinessCheck $c): array => $c->toArray(), $checks),
            'ready' => $readiness->requiredMet(),
            'mode' => $settings->mode->value,
            'mode_label' => $settings->mode->label(),
            'override' => $settings->readiness_override_at === null ? null : [
                'at' => $settings->readiness_override_at->setTimezone($context->hotel()->timezone)->format('Y-m-d H:i'),
                'reason' => $settings->readiness_override_reason,
            ],
            'drafts' => [
                'handled' => $handled,
                'sent_unchanged' => (int) ($outcomes['sent_unchanged'] ?? 0),
                'edited' => (int) ($outcomes['edited'] ?? 0),
                'discarded' => (int) ($outcomes['discarded'] ?? 0),
                'unchanged_rate' => $handled === 0 ? null : round(100 * (int) ($outcomes['sent_unchanged'] ?? 0) / $handled),
            ],
        ]);
    }
}
