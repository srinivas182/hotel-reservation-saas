<?php

declare(strict_types=1);

namespace App\Domains\Platform;

use App\Domains\Ai\AssistantMode;
use App\Domains\Ai\Models\AiRun;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Billing\CreditKind;
use App\Domains\Billing\Credits;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Subscription;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Onboarding\Models\OnboardingProgress;
use App\Domains\Onboarding\SetupChecklist;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;

/**
 * One hotel's health and activity, as the platform sees it.
 */
final class HotelHealth
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Hotel $hotel): array
    {
        return $this->context->run($hotel, function () use ($hotel): array {
            $account = WhatsAppAccount::query()->first();
            $lastWebhook = $account === null ? null : WebhookEvent::query()->where('phone_number_id', $account->phone_number_id)->max('received_at');
            $mode = HotelAssistantSettings::current()->mode;
            $subscription = Subscription::query()->first();
            $credits = app(Credits::class);
            $since = CarbonImmutable::now()->startOfMonth();
            $aiRuns = AiRun::query()->where('created_at', '>=', $since);
            $aiTotal = (clone $aiRuns)->count();
            $aiFailed = (clone $aiRuns)->where('outcome', 'error')->count();
            $progress = OnboardingProgress::query()->first();

            $issues = [];
            if ($account !== null && $account->status !== AccountStatus::Connected) {
                $issues[] = 'whatsapp_disconnected';
            }
            if ($mode !== AssistantMode::Off && ($lastWebhook === null || CarbonImmutable::parse((string) $lastWebhook)->lt(now()->subDay()))) {
                $issues[] = 'webhook_silent';
            }
            if ($subscription !== null && ! $credits->canUse(CreditKind::Ai)) {
                $issues[] = 'ai_credits';
            }
            $overdue = Invoice::query()->where('hotel_id', $hotel->id)->where('kind', 'invoice')->whereNull('paid_at')->get()->filter(fn (Invoice $i): bool => $i->status() === 'overdue');
            if ($overdue->isNotEmpty()) {
                $issues[] = 'overdue_invoice';
            }
            if ($progress?->golive_requested_at !== null && $progress->golive_decision === null) {
                $issues[] = 'golive_request';
            }
            if (\App\Domains\Support\Models\SupportTicket::query()->where('hotel_id', $hotel->id)->whereNull('first_responded_at')->where('status', '!=', 'solved')->where('first_response_due_at', '<', now())->exists()) {
                $issues[] = 'support_late';
            }

            return [
                'status' => $hotel->status->value,
                'mode' => $mode->value,
                'plan' => $subscription?->plan?->code,
                'subscription_status' => $subscription?->status,
                'trial_ends' => $subscription?->trial_ends_at?->toDateString(),
                'whatsapp' => $account === null ? null : [
                    'status' => $account->status->value, 'phone' => $account->display_phone, 'quality' => $account->quality_rating,
                    'limit' => $account->messaging_limit, 'last_error' => $account->last_error, 'token_checked_at' => $account->token_checked_at?->toDateTimeString(),
                ],
                'last_webhook' => $lastWebhook === null ? null : CarbonImmutable::parse((string) $lastWebhook)->toDateTimeString(),
                'ai_failure_rate' => $aiTotal === 0 ? null : round(100 * $aiFailed / $aiTotal, 1),
                'credits' => $subscription === null ? null : ['ai' => $credits->summary(CreditKind::Ai), 'email' => $credits->summary(CreditKind::Email)],
                'setup' => app(SetupChecklist::class)->summary(),
                'usage' => [
                    'conversations_month' => Conversation::query()->where('last_message_at', '>=', $since)->count(),
                    'messages_month' => Message::query()->where('created_at', '>=', $since)->count(),
                    'bookings_month' => Booking::query()->where('created_at', '>=', $since)->count(),
                    'handovers_month' => Conversation::query()->where('last_message_at', '>=', $since)->whereNotNull('handover_reason')->count(),
                ],
                'overdue_minor' => (int) $overdue->sum(fn (Invoice $i): int => $i->balance()),
                'issues' => $issues,
            ];
        });
    }

    public static function issueLabel(string $issue): string
    {
        return match ($issue) {
            'whatsapp_disconnected' => __('WhatsApp not connected'),
            'webhook_silent' => __('No WhatsApp notification for 24 hours'),
            'ai_credits' => __('AI credits used up'),
            'overdue_invoice' => __('Overdue invoice'),
            'golive_request' => __('Asks to go live'),
            'support_late' => __('Support request waiting past its target'),
            default => $issue,
        };
    }
}
