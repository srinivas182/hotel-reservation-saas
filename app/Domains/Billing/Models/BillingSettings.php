<?php

declare(strict_types=1);

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform billing settings: how many credits each AI task costs (in
 * hundredths of a credit) and the buffer allowed once credits run out.
 *
 * @property int $id
 * @property array<string, int>|null $ai_weights
 * @property int $buffer_percent
 */
class BillingSettings extends Model
{
    public const DEFAULT_WEIGHTS = ['assistant' => 100, 'transcription' => 100, 'translation' => 50];

    protected $guarded = ['id'];

    protected $attributes = ['buffer_percent' => 5];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['ai_weights' => 'array', 'buffer_percent' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    public function weight(string $task): int
    {
        return (int) (($this->ai_weights ?? [])[$task] ?? self::DEFAULT_WEIGHTS[$task] ?? 100);
    }
}
