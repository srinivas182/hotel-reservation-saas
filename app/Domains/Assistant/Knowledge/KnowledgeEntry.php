<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Knowledge;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/**
 * A draft entry of the hotel information pack.
 *
 * @property int $id
 * @property string $hotel_id
 * @property string $locale
 * @property KnowledgeSection $section
 * @property string|null $question
 * @property string $content
 * @property int $sort_order
 */
class KnowledgeEntry extends Model
{
    use BelongsToHotel;

    protected $fillable = ['locale', 'section', 'question', 'content', 'sort_order'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['section' => KnowledgeSection::class, 'sort_order' => 'integer'];
    }
}
