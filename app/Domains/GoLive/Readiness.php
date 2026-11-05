<?php

declare(strict_types=1);

namespace App\Domains\GoLive;

use App\Domains\Ai\Evals\AiEvalRun;
use App\Domains\Ai\Models\AiModel;
use App\Domains\Ai\Models\AiPlatformSettings;
use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Ai\ModelStatus;
use App\Domains\Assistant\Knowledge\KnowledgePack;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Platform\Health\HeartbeatCheck;
use App\Domains\Rates\Models\CategoryRate;
use App\Domains\Rooms\Models\Room;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\StayMessages\StayMessenger;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Illuminate\Support\Facades\Cache;

/**
 * Everything that must be in place before the assistant answers a hotel's
 * guests (required) or should be (recommended). Current hotel.
 */
final class Readiness
{
    /** Cache key S8.2's backup job writes after a successful backup. */
    public const BACKUP_KEY = 'backups:last_success_at';

    public function __construct(
        private readonly TenantContext $context,
        private readonly KnowledgePack $knowledge,
    ) {}

    /**
     * @return list<ReadinessCheck>
     */
    public function checks(): array
    {
        $hotel = $this->context->hotel();
        $settings = HotelAssistantSettings::current();
        $hotelSettings = HotelSettings::current();
        $account = WhatsAppAccount::query()->first();

        $checks = [];

        $connected = $account !== null && $account->status === AccountStatus::Connected;
        $checks[] = new ReadinessCheck('whatsapp_connected', __('WhatsApp number connected'), true, $connected, $account?->display_phone, 'hotel.whatsapp');

        $recent = $account !== null && $this->context->bypass('Readiness: recent webhook', fn (): bool => WebhookEvent::query()
            ->where('phone_number_id', $account->phone_number_id)
            ->where('received_at', '>', now()->subDay())
            ->exists());
        $checks[] = new ReadinessCheck('webhook_recent', __('A WhatsApp notification received in the last 24 hours'), true, $recent, $recent ? null : (string) __('Send a test message to the hotel number.'));

        $missing = $this->missingTemplates($settings, $hotel->default_locale);
        $checks[] = new ReadinessCheck('templates_approved', __('Message templates approved by Meta'), true, $missing === [], $missing === [] ? null : (string) __('Not approved yet: :names', ['names' => implode(', ', $missing)]), 'hotel.whatsapp.templates');

        [$aiOk, $aiDetail] = $this->aiModels();
        $checks[] = new ReadinessCheck('ai_models', __('AI models approved and test suite passed'), true, $aiOk, $aiDetail);

        $published = $this->knowledge->published();
        $checks[] = new ReadinessCheck('knowledge_published', __('Information pack published'), true, $published !== null, $published === null ? null : (string) __('Version :version', ['version' => $published->version]), 'hotel.assistant.knowledge');

        [$roomsOk, $roomsDetail] = $this->roomsAndRates();
        $checks[] = new ReadinessCheck('rooms_rates', __('Every room category has rooms and a rate'), true, $roomsOk, $roomsDetail, 'hotel.rates');

        $hours = array_filter((array) ($hotelSettings->staffed_hours ?? []));
        $checks[] = new ReadinessCheck('staffed_hours', __('Reception hours set'), true, $hours !== [], null, 'hotel.settings.edit');

        if ($settings->deposit_required) {
            $instructions = trim((string) ($settings->deposit_instructions[$hotel->default_locale] ?? ''));
            $checks[] = new ReadinessCheck('deposit_instructions', __('Deposit payment instructions set'), true, $instructions !== '', null, 'hotel.assistant');
        }

        $alerted = StaffAlertSettings::query()->whereNotNull('alert_phone')->get()->contains(fn (StaffAlertSettings $s): bool => ($s->alerts ?? []) !== []);
        $checks[] = new ReadinessCheck('staff_alerts', __('At least one person receives WhatsApp alerts'), true, $alerted, null, 'hotel.my-alerts');

        $scheduler = HeartbeatCheck::scheduler()->run();
        $queue = HeartbeatCheck::queue()->run();
        $checks[] = new ReadinessCheck('background_jobs', __('Background jobs and scheduler running'), true, $scheduler['ok'] && $queue['ok'], $scheduler['ok'] && $queue['ok'] ? null : trim(($scheduler['detail'] ?? '').' '.($queue['detail'] ?? '')));

        $backup = Cache::get(self::BACKUP_KEY);
        $backupOk = is_string($backup) && now()->parse($backup)->gt(now()->subHours(26));
        $checks[] = new ReadinessCheck('backup_recent', __('Backup succeeded in the last 26 hours'), true, $backupOk, is_string($backup) ? $backup : (string) __('No successful backup recorded.'));

        $checks[] = new ReadinessCheck('review_url', __('Review link set'), false, trim((string) $settings->review_url) !== '', null, 'hotel.assistant');
        $checks[] = new ReadinessCheck('owner_summary', __('An owner receives the daily summary'), false, StaffAlertSettings::query()->whereNotNull('summary_time')->get()->contains(fn (StaffAlertSettings $s): bool => ($s->summary_channels ?? []) !== []), null, 'hotel.my-alerts');
        $stay = array_filter(StayMessenger::settings($settings), fn (array $c): bool => $c['enabled']);
        $checks[] = new ReadinessCheck('stay_messages', __('Stay messages chosen'), false, $stay !== [], null, 'hotel.assistant');
        $checks[] = new ReadinessCheck('privacy_url', __('Privacy notice link set'), false, trim((string) $settings->privacy_url) !== '', null, 'hotel.assistant');

        return $checks;
    }

    public function requiredMet(): bool
    {
        foreach ($this->checks() as $check) {
            if ($check->required && ! $check->ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function missingTemplates(HotelAssistantSettings $settings, string $locale): array
    {
        $needed = ['booking_confirmed', 'booking_declined', 'request_expired', 'staff_alert'];

        if ($settings->deposit_required) {
            $needed[] = 'booking_deposit_request';
        }

        foreach (StayMessenger::settings($settings) as $kind => $choice) {
            if ($choice['enabled']) {
                $needed[] = \App\Domains\StayMessages\StayMessageKind::from($kind)->template();
            }
        }

        $approved = MessageTemplate::query()->where('language', $locale)->where('status', TemplateStatus::Approved->value)->pluck('name')->all();

        return array_values(array_diff($needed, $approved));
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function aiModels(): array
    {
        $platform = AiPlatformSettings::current();
        $assistant = $platform->assistant_model_id === null ? null : AiModel::query()->find($platform->assistant_model_id);

        if ($assistant === null || $assistant->status !== ModelStatus::Approved) {
            return [false, (string) __('No approved assistant model is set.')];
        }

        $passed = AiEvalRun::query()->where('ai_model_id', $assistant->id)->where('meets_threshold', true)->exists();

        if (! $passed) {
            return [false, (string) __(':model has not passed the test suite.', ['model' => $assistant->label])];
        }

        $transcription = $platform->transcription_model_id === null ? null : AiModel::query()->find($platform->transcription_model_id);

        if ($transcription === null || $transcription->status !== ModelStatus::Approved) {
            return [false, (string) __('No approved voice-note model is set.')];
        }

        return [true, $assistant->label];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function roomsAndRates(): array
    {
        $categories = RoomCategory::query()->where('active', true)->get();

        if ($categories->isEmpty()) {
            return [false, (string) __('No room categories yet.')];
        }

        $withRooms = Room::query()->distinct()->pluck('room_category_id')->all();
        $withRate = CategoryRate::query()->pluck('room_category_id')->all();
        $incomplete = $categories->filter(fn (RoomCategory $c): bool => ! in_array($c->id, $withRooms, true) || ! in_array($c->id, $withRate, true))
            ->map(fn (RoomCategory $c): string => $c->code)->values()->all();

        return [$incomplete === [], $incomplete === [] ? null : (string) __('Incomplete: :codes', ['codes' => implode(', ', $incomplete)])];
    }
}
