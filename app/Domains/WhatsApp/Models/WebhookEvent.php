<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One change from a Meta webhook delivery, stored encrypted for
 * troubleshooting and replay. Platform-level: events are matched to a
 * hotel by phone number (or WABA) when they are processed.
 *
 * @property int $id
 * @property CarbonImmutable $received_at
 * @property string|null $phone_number_id
 * @property string|null $waba_id
 * @property string $kind
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $processed_at
 * @property int $attempts
 * @property string|null $error
 */
class WebhookEvent extends Model
{
    use MassPrunable;

    protected $table = 'whatsapp_webhook_events';

    protected $fillable = ['received_at', 'phone_number_id', 'waba_id', 'kind', 'payload', 'processed_at', 'attempts', 'error'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'payload' => 'encrypted:array',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('received_at', '<', now()->subDays((int) config('whatsapp.webhook_retention_days', 30)));
    }
}
