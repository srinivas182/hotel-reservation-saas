<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform view of the Meta setup and every connected number.
 */
final class WhatsAppPlatformController extends Controller
{
    public function __invoke(TenantContext $context): Response
    {
        $accounts = $context->bypass('Platform WhatsApp overview', fn () => WhatsAppAccount::query()->orderBy('created_at')->get());
        $hotels = Hotel::query()->whereIn('id', $accounts->pluck('hotel_id'))->pluck('name', 'id');

        return Inertia::render('platform/whatsapp', [
            'config' => [
                'app_id' => config('whatsapp.app_id'),
                'config_id' => config('whatsapp.config_id'),
                'graph_version' => config('whatsapp.graph_version'),
                'app_secret_set' => filled(config('whatsapp.app_secret')),
                'verify_token_set' => filled(config('whatsapp.verify_token')),
                'webhook_url' => route('webhooks.whatsapp.receive'),
            ],
            'lastWebhookAt' => Cache::get('whatsapp:last_webhook_at'),
            'failedEvents' => WebhookEvent::query()->whereNull('processed_at')->where('attempts', '>=', 5)->count(),
            'accounts' => $accounts->map(fn (WhatsAppAccount $account): array => [
                'hotel' => $hotels[$account->hotel_id] ?? '—',
                'display_phone' => $account->display_phone,
                'verified_name' => $account->verified_name,
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'quality_rating' => $account->quality_rating,
                'coexistence' => $account->coexistence,
                'last_error' => $account->last_error,
            ])->values(),
        ]);
    }
}
