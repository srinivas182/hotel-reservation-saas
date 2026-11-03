<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\AiTask;
use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI call for a hotel. The request and reply are encrypted and the
 * record is deleted after 30 days (usage totals are kept in the usage
 * records).
 *
 * @property int $id
 * @property string $hotel_id
 * @property string|null $conversation_id
 * @property AiTask $task
 * @property int|null $ai_model_id
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $audio_seconds
 * @property int $cost_micro_usd
 * @property int $latency_ms
 * @property string $outcome ok, error, blocked
 * @property string|null $error
 * @property array<string, mixed>|null $guardrails
 * @property string|null $request
 * @property string|null $response
 * @property CarbonImmutable|null $created_at
 */
class AiRun extends Model
{
    use BelongsToHotel, MassPrunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'conversation_id', 'task', 'ai_model_id', 'input_tokens', 'output_tokens', 'audio_seconds',
        'cost_micro_usd', 'latency_ms', 'outcome', 'error', 'guardrails', 'request', 'response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'guardrails' => 'array',
            'request' => 'encrypted',
            'response' => 'encrypted',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
