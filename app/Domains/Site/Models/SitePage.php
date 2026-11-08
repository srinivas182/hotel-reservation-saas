<?php

declare(strict_types=1);

namespace App\Domains\Site\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property array<string, string> $title
 * @property array<string, string> $description
 * @property array<string, string> $body
 * @property bool $published
 */
class SitePage extends Model
{
    public const KEYS = ['home', 'features', 'countries', 'faq'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'description' => 'array', 'body' => 'array', 'published' => 'boolean'];
    }

    public function text(string $field, string $locale): string
    {
        $values = $this->{$field};

        return (string) ($values[$locale] ?? $values['fr'] ?? '');
    }
}
