<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Webhooks;

use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Models\WebhookEvent;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Routes a stored webhook event to its hotel and handler. Events whose
 * kind has no handler yet stay unprocessed so they can be replayed once a
 * handler exists. Events for numbers no hotel has connected are closed.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly int $eventId) {}

    /**
     * @param  iterable<WebhookHandler>  $handlers
     */
    public static function handlerFor(string $kind, iterable $handlers): ?WebhookHandler
    {
        foreach ($handlers as $handler) {
            if (in_array($kind, $handler->kinds(), true)) {
                return $handler;
            }
        }

        return null;
    }

    public function handle(TenantContext $context): void
    {
        $event = WebhookEvent::query()->find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $account = $context->bypass('WhatsApp webhook routing', fn (): ?WhatsAppAccount => WhatsAppAccount::query()
            ->when($event->phone_number_id !== null,
                fn ($query) => $query->where('phone_number_id', $event->phone_number_id),
                fn ($query) => $query->where('waba_id', $event->waba_id))
            ->first());

        if ($account === null) {
            $event->forceFill(['processed_at' => now(), 'error' => 'No hotel has this number.'])->save();

            return;
        }

        $handler = self::handlerFor($event->kind, app()->tagged('whatsapp.webhook-handlers'));

        if ($handler === null) {
            return;
        }

        $hotel = Hotel::query()->findOrFail($account->hotel_id);
        $event->increment('attempts');

        try {
            $context->run($hotel, function () use ($handler, $event, $account): void {
                $handler->handle($event, $account->fresh() ?? $account);
            });
        } catch (Throwable $exception) {
            $event->forceFill(['error' => Str::limit($exception->getMessage(), 490)])->save();

            throw $exception;
        }

        $event->forceFill(['processed_at' => now(), 'error' => null])->save();
    }
}
