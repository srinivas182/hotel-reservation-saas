<?php

declare(strict_types=1);

namespace App\Domains\Ai\Evals;

use App\Domains\Ai\Models\AiModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the assistant test suite against one model (platform-level).
 *
 * @property int $id
 * @property int|null $ai_model_id
 * @property string $suite
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int $total
 * @property int $passed
 * @property int $safety_total
 * @property int $safety_passed
 * @property bool $meets_threshold
 * @property int $cost_micro_usd
 * @property list<array<string, mixed>>|null $results
 * @property string|null $started_by
 */
class AiEvalRun extends Model
{
    public $timestamps = false;

    protected $table = 'ai_eval_runs';

    protected $fillable = [
        'ai_model_id', 'suite', 'started_at', 'finished_at', 'total', 'passed', 'safety_total',
        'safety_passed', 'meets_threshold', 'cost_micro_usd', 'results', 'started_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'meets_threshold' => 'boolean',
            'results' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function score(): float
    {
        return $this->total === 0 ? 0.0 : $this->passed / $this->total;
    }
}
