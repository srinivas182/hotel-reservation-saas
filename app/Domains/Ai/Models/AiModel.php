<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\ModelStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model on the approved list. Prices are in micro-US dollars.
 *
 * @property int $id
 * @property int $ai_provider_id
 * @property string $model
 * @property string $label
 * @property list<string> $capabilities chat, tools, transcription
 * @property ModelStatus $status
 * @property int $input_cost_per_mtok
 * @property int $output_cost_per_mtok
 * @property int $audio_cost_per_minute
 * @property int|null $last_eval_run_id
 * @property CarbonImmutable|null $certified_at
 * @property-read AiProvider|null $provider
 */
class AiModel extends Model
{
    protected $fillable = [
        'ai_provider_id', 'model', 'label', 'capabilities', 'status',
        'input_cost_per_mtok', 'output_cost_per_mtok', 'audio_cost_per_minute',
        'last_eval_run_id', 'certified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'status' => ModelStatus::class,
            'input_cost_per_mtok' => 'integer',
            'output_cost_per_mtok' => 'integer',
            'audio_cost_per_minute' => 'integer',
            'certified_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AiProvider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * Cost of a call in micro-US dollars, rounded up.
     */
    public function cost(int $inputTokens, int $outputTokens, int $audioSeconds = 0): int
    {
        $micro = ($inputTokens * $this->input_cost_per_mtok + $outputTokens * $this->output_cost_per_mtok) / 1_000_000
            + $audioSeconds * $this->audio_cost_per_minute / 60;

        return (int) ceil($micro);
    }
}
