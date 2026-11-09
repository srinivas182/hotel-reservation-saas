<?php

declare(strict_types=1);

namespace App\Domains\Product\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * E: release notes written for hotels ("What's new").
 *
 * @property int $id
 * @property string|null $version
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property string|null $media_path
 * @property string|null $try_path
 * @property CarbonImmutable|null $published_at
 */
class ReleaseNote extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'body' => 'array', 'published_at' => 'immutable_datetime'];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function text(string $field, string $locale): string
    {
        $values = $this->{$field};

        return (string) ($values[$locale] ?? $values['fr'] ?? '');
    }

    public function html(string $locale): string
    {
        return Str::markdown($this->text('body', $locale), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }
}
