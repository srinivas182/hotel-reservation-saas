<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Webhooks\ProcessWebhookEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:replay {--kind= : Only this webhook kind} {--failed : Also retry events that failed after retries}')]
#[Description('Process stored WhatsApp webhook events that have not been processed yet')]
final class ReplayWhatsAppEvents extends Command
{
    public function handle(): int
    {
        $query = WebhookEvent::query()
            ->whereNull('processed_at')
            ->when(is_string($this->option('kind')), fn ($q) => $q->where('kind', $this->option('kind')))
            ->when(! $this->option('failed'), fn ($q) => $q->where('attempts', '<', 5))
            ->orderBy('id');

        $count = 0;
        foreach ($query->lazyById() as $event) {
            ProcessWebhookEvent::dispatch($event->id);
            $count++;
        }

        $this->components->info("{$count} event(s) queued.");

        return self::SUCCESS;
    }
}
