<?php

declare(strict_types=1);

namespace App\Domains\Operations;

use App\Domains\Ai\Models\AiRun;
use App\Domains\Conversations\MessageStatus;
use App\Domains\Conversations\Models\Message;
use App\Domains\Operations\Mail\OpsAlertMail;
use App\Domains\Platform\Health\HeartbeatCheck;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Platform health problems MCS must hear about before the hotels do. Each
 * problem opens an incident: one email when it starts, a reminder every
 * two hours while it lasts, and one when it clears.
 */
final class OpsCheck
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, string> key => detail, for every current problem
     */
    public function problems(): array
    {
        $problems = [];

        $backlog = $this->queueSize();
        if ($backlog > (int) config('ops.queue_backlog')) {
            $problems['queue_backlog'] = "{$backlog} background jobs are waiting.";
        }

        foreach ([HeartbeatCheck::scheduler(), HeartbeatCheck::queue()] as $heartbeat) {
            $result = $heartbeat->run();
            if (! $result['ok']) {
                $problems[$heartbeat->name().'_stopped'] = (string) ($result['detail'] ?? 'No recent heartbeat.');
            }
        }

        $problems += $this->webhooks();
        $problems += $this->failureRates();
        $problems += $this->backups();

        return $problems;
    }

    public function run(): int
    {
        $current = $this->problems();
        $open = OpsIncident::query()->whereNull('resolved_at')->get()->keyBy('key');
        $changes = 0;

        foreach ($current as $key => $detail) {
            /** @var OpsIncident|null $incident */
            $incident = $open->get($key);

            if ($incident === null) {
                $incident = OpsIncident::query()->create(['key' => $key, 'title' => self::title($key), 'detail' => $detail, 'opened_at' => now(), 'last_notified_at' => now()]);
                $this->notify($incident, 'opened');
                $changes++;
            } elseif ($incident->last_notified_at === null || $incident->last_notified_at->lte(now()->subMinutes((int) config('ops.reminder_minutes')))) {
                $incident->forceFill(['detail' => $detail, 'last_notified_at' => now()])->save();
                $this->notify($incident, 'reminder');
                $changes++;
            }
        }

        foreach ($open as $key => $incident) {
            if (! array_key_exists($key, $current)) {
                $incident->forceFill(['resolved_at' => now()])->save();
                $this->notify($incident, 'resolved');
                $changes++;
            }
        }

        return $changes;
    }

    public static function title(string $key): string
    {
        return match ($key) {
            'queue_backlog' => 'Background jobs are backing up',
            'scheduler_stopped' => 'The scheduler has stopped',
            'queue_stopped' => 'Background workers have stopped',
            'webhook_silence' => 'No WhatsApp notifications are arriving',
            'webhook_failures' => 'WhatsApp notifications are failing to process',
            'ai_failures' => 'AI providers are failing',
            'whatsapp_failures' => 'WhatsApp messages are failing to send',
            'backup_missing' => 'No recent successful backup',
            'backup_failed' => 'The last backup failed',
            'restore_drill' => 'The restore drill failed or is overdue',
            default => $key,
        };
    }

    /**
     * @return array<string, string>
     */
    private function webhooks(): array
    {
        $problems = [];
        [$from, $to] = config('ops.daytime_utc');
        $hour = (int) now()->utc()->format('G');

        $connected = $this->context->bypass('Ops check: connected numbers', fn (): bool => WhatsAppAccount::query()->where('status', AccountStatus::Connected->value)->exists());
        $hours = (int) config('ops.webhook_silence_hours');

        if ($connected && $hour >= $from && $hour < $to && ! WebhookEvent::query()->where('received_at', '>', now()->subHours($hours))->exists()) {
            $problems['webhook_silence'] = "Nothing received from Meta for {$hours} hours.";
        }

        $failed = WebhookEvent::query()->whereNull('processed_at')->where('attempts', '>=', 5)->where('received_at', '>', now()->subDay())->count();
        if ($failed > 0) {
            $problems['webhook_failures'] = "{$failed} notifications failed after retries in the last 24 hours. Replay with: php artisan whatsapp:replay --failed";
        }

        return $problems;
    }

    /**
     * @return array<string, string>
     */
    private function failureRates(): array
    {
        $problems = [];

        [$aiTotal, $aiFailed] = $this->context->bypass('Ops check: AI failures', fn (): array => [
            AiRun::query()->where('created_at', '>', now()->subHour())->count(),
            AiRun::query()->where('created_at', '>', now()->subHour())->where('outcome', 'error')->count(),
        ]);
        $ai = config('ops.ai_failure');
        if ($aiFailed >= $ai['min'] && $aiFailed / max(1, $aiTotal) >= $ai['rate']) {
            $problems['ai_failures'] = "{$aiFailed} of {$aiTotal} AI calls failed in the last hour.";
        }

        [$sent, $failed] = $this->context->bypass('Ops check: WhatsApp failures', fn (): array => [
            Message::query()->where('direction', 'out')->where('sent_at', '>', now()->subHour())->whereNotIn('status', [MessageStatus::Draft->value, MessageStatus::Discarded->value])->count(),
            Message::query()->where('direction', 'out')->where('sent_at', '>', now()->subHour())->where('status', MessageStatus::Failed->value)->count(),
        ]);
        $wa = config('ops.whatsapp_failure');
        if ($failed >= $wa['min'] && $failed / max(1, $sent) >= $wa['rate']) {
            $problems['whatsapp_failures'] = "{$failed} of {$sent} WhatsApp messages failed in the last hour.";
        }

        return $problems;
    }

    /**
     * @return array<string, string>
     */
    private function backups(): array
    {
        if (! config('ops.backups_enabled')) {
            return [];
        }

        $problems = [];
        $last = BackupRun::last('backup');
        $success = BackupRun::lastSuccess('backup');

        if ($last !== null && $last->status === 'failure') {
            $problems['backup_failed'] = 'Backup failed: '.($last->details['error'] ?? 'see the server log');
        } elseif ($success === null || $success->created_at?->lt(now()->subHours((int) config('ops.backup_max_age_hours')))) {
            $problems['backup_missing'] = 'Last successful backup: '.($success?->created_at?->toDateTimeString() ?? 'never');
        }

        $drill = BackupRun::last('restore_drill');
        if ($drill === null || $drill->status === 'failure' || $drill->created_at?->lt(now()->subDays((int) config('ops.restore_drill_max_age_days')))) {
            $problems['restore_drill'] = $drill === null ? 'No restore drill has been run.' : ($drill->status === 'failure' ? 'The last restore drill failed.' : 'The last restore drill is older than '.config('ops.restore_drill_max_age_days').' days.');
        }

        return $problems;
    }

    public function queueSize(): int
    {
        try {
            return Queue::size();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  'opened'|'reminder'|'resolved'  $state
     */
    private function notify(OpsIncident $incident, string $state): void
    {
        Log::log($state === 'resolved' ? 'info' : 'error', "Ops {$state}: {$incident->title}", ['key' => $incident->key, 'detail' => $incident->detail]);

        $to = array_filter(array_map('trim', explode(',', (string) config('ops.alert_email'))));

        if ($to !== []) {
            try {
                Mail::to($to)->send(new OpsAlertMail($incident, $state));
            } catch (Throwable $e) {
                Log::error('Ops alert email failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
