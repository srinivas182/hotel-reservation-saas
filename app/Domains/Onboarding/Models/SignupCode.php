<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string|null $note
 * @property int|null $max_uses
 * @property int $uses
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
class SignupCode extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['max_uses' => 'integer', 'uses' => 'integer', 'expires_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }

    public function usable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->uses < $this->max_uses);
    }
}
