<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the current hotel is in its setup.
 *
 * @property int $id
 * @property string $hotel_id
 * @property array<string, array{skipped_at?: string, confirmed_at?: string}>|null $steps
 * @property array<string, string>|null $questionnaire
 * @property CarbonImmutable|null $golive_requested_at
 * @property CarbonImmutable|null $golive_decided_at
 * @property string|null $golive_decision
 * @property string|null $golive_reason
 * @property array<string, string>|null $nudges_sent
 * @property CarbonImmutable|null $completed_at
 */
class OnboardingProgress extends Model
{
    use BelongsToHotel;

    protected $table = 'onboarding_progress';

    protected $guarded = ['id', 'hotel_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'steps' => 'array', 'questionnaire' => 'array', 'nudges_sent' => 'array',
            'golive_requested_at' => 'immutable_datetime', 'golive_decided_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([]);
    }

    public function mark(string $step, string $flag, bool $on = true): void
    {
        $steps = $this->steps ?? [];
        $entry = $steps[$step] ?? [];

        if ($on) {
            $entry[$flag] = now()->toIso8601String();
        } else {
            unset($entry[$flag]);
        }

        $steps[$step] = $entry;
        $this->forceFill(['steps' => $steps])->save();
    }

    public function flagged(string $step, string $flag): bool
    {
        return isset(($this->steps ?? [])[$step][$flag]);
    }
}
