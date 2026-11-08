<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property array<string, string> $body
 */
class SavedReply extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['body' => 'array'];
    }

    /**
     * The reply in a language, with the hotel, the person and the agent filled in.
     *
     * @param  array{hotel: string, name: string, agent: string}  $values
     */
    public function personalised(string $locale, array $values): string
    {
        return strtr((string) ($this->body[$locale] ?? $this->body['fr'] ?? ''), ['{hotel}' => $values['hotel'], '{name}' => $values['name'], '{agent}' => $values['agent']]);
    }
}
