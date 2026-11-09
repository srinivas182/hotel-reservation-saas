<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * S16.4 drills: checks what happened to the simulated guests who wrote since
 * a moment (every one answered once, or every one handed to staff), with
 * no failed or duplicated sends and no guest message stored twice.
 */
#[Signature('simulation:check {--since= : ISO date-time} {--expect=replies : replies or handover}')]
#[Description('Check the simulated conversations since a moment (staging drills)')]
final class SimulationCheck extends Command
{
    public function handle(TenantContext $context): int
    {
        $since = CarbonImmutable::parse((string) ($this->option('since') ?: now()->subHour()->toIso8601String()));
        $expect = (string) $this->option('expect');
        $hotel = Hotel::query()->where('slug', 'first-class-lodge')->firstOrFail();

        $result = $context->run($hotel, function () use ($since, $expect): array {
            $conversations = Conversation::query()->whereHas('messages', fn ($q) => $q->where('direction', 'in')->where('created_at', '>=', $since))->get();
            $missing = 0;
            foreach ($conversations as $conversation) {
                $ok = $expect === 'handover'
                    ? str_starts_with((string) $conversation->handover_reason, 'ai_unavailable')
                    : Message::query()->where('conversation_id', $conversation->id)->where('direction', 'out')->where('created_at', '>=', $since)->exists();
                $missing += $ok ? 0 : 1;
            }
            $messages = Message::query()->where('created_at', '>=', $since);

            return [
                'conversations' => $conversations->count(),
                'missing' => $missing,
                'failed' => (clone $messages)->where('status', 'failed')->count(),
                'duplicates' => (clone $messages)->whereNotNull('wamid')->count() - (clone $messages)->whereNotNull('wamid')->distinct()->count('wamid'),
            ];
        });

        $this->table(['Conversations', 'Not '.($expect === 'handover' ? 'handed over' : 'answered'), 'Failed sends', 'Duplicates'], [array_values($result)]);
        $pass = $result['conversations'] > 0 && $result['missing'] === 0 && $result['failed'] === 0 && $result['duplicates'] === 0;
        $this->line($pass ? 'PASS' : 'FAIL');

        return $pass ? self::SUCCESS : self::FAILURE;
    }
}
