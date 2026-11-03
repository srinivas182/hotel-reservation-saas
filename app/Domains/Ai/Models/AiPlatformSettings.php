<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\AiTask;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The platform's AI settings (one row): main and backup model per task,
 * request timeout and the platform-wide off switch.
 *
 * @property int $id
 * @property int|null $assistant_model_id
 * @property int|null $assistant_fallback_id
 * @property int|null $transcription_model_id
 * @property int|null $transcription_fallback_id
 * @property int $timeout_seconds
 * @property bool $kill_switch
 */
class AiPlatformSettings extends Model
{
    protected $table = 'ai_platform_settings';

    /** Defaults for a new row (the database defaults, available before saving). */
    protected $attributes = [
        'timeout_seconds' => 20,
        'kill_switch' => false,
    ];

    protected $fillable = [
        'assistant_model_id', 'assistant_fallback_id', 'transcription_model_id',
        'transcription_fallback_id', 'timeout_seconds', 'kill_switch',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['timeout_seconds' => 'integer', 'kill_switch' => 'boolean'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    /**
     * Main model first, then the backup (only usable ones).
     *
     * @return list<AiModel>
     */
    public function modelsFor(AiTask $task): array
    {
        $ids = match ($task) {
            AiTask::Assistant => [$this->assistant_model_id, $this->assistant_fallback_id],
            AiTask::Transcription => [$this->transcription_model_id, $this->transcription_fallback_id],
        };

        $models = AiModel::query()->with('provider')->whereIn('id', array_filter($ids))->get()->keyBy('id');

        $ordered = [];
        foreach (array_unique(array_filter($ids)) as $id) {
            $model = $models->get($id);

            if ($model !== null && $model->provider?->active === true && $model->status->value !== 'retired') {
                $ordered[] = $model;
            }
        }

        return $ordered;
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function assistantModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'assistant_model_id');
    }
}
