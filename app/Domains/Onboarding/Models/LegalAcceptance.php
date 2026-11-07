<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $user_id
 * @property string|null $hotel_id
 * @property int $legal_document_id
 */
class LegalAcceptance extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['accepted_at' => 'immutable_datetime'];
    }
}
