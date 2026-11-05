<?php

declare(strict_types=1);

use App\Domains\Ai\Evals\AiEvalRun;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\AiProvider;
use App\Domains\GoLive\Readiness;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\StarterTemplates;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Make every required go-live check pass for an assistant hotel.
 */
function makeReady(Hotel $hotel, User $alerted): void
{
    $platform = AiPlatformSettings::current();
    $assistant = AiModel::query()->findOrFail($platform->assistant_model_id);
    AiEvalRun::query()->create(['ai_model_id' => $assistant->id, 'suite' => 'assistant', 'started_at' => now(), 'finished_at' => now(), 'total' => 65, 'passed' => 64, 'safety_total' => 27, 'safety_passed' => 27, 'meets_threshold' => true]);
    $provider = AiProvider::query()->firstOrFail();
    $voice = AiModel::query()->create(['ai_provider_id' => $provider->id, 'model' => 'voice', 'label' => 'Voice', 'capabilities' => ['transcription'], 'status' => 'approved']);
    $platform->update(['transcription_model_id' => $voice->id]);

    Cache::put('health:heartbeat:scheduler', now()->getTimestamp());
    Cache::put('health:heartbeat:queue', now()->getTimestamp());
    Cache::put(Readiness::BACKUP_KEY, now()->subHour()->toIso8601String());

    app(TenantContext::class)->run($hotel, function () use ($alerted): void {
        WhatsAppAccount::query()->firstOrCreate([], ['waba_id' => '9000', 'phone_number_id' => '5550001', 'status' => AccountStatus::Connected, 'token' => 't']);
        HotelSettings::current()->update(['staffed_hours' => ['mon' => [['from' => '08:00', 'to' => '20:00']]]]);
        StaffAlertSettings::query()->create(['user_id' => $alerted->id, 'alert_phone' => '+2250700000010', 'alerts' => ['handover']]);

        foreach (['booking_confirmed', 'booking_declined', 'request_expired', 'staff_alert'] as $name) {
            $starter = StarterTemplates::all()[$name];
            MessageTemplate::query()->create(['starter_key' => $name, 'name' => $name, 'language' => 'fr', 'category' => 'UTILITY', 'body' => $starter['bodies']['fr'], 'examples' => $starter['examples'], 'status' => TemplateStatus::Approved]);
        }
    });

    WebhookEvent::query()->create(['received_at' => now(), 'phone_number_id' => '5550001', 'waba_id' => '9000', 'kind' => 'messages', 'payload' => [], 'processed_at' => now()]);
}
