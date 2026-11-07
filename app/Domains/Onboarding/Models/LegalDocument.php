<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A version of the terms of service, privacy notice or data processing
 * agreement. Published versions are never edited; a change is a new one.
 *
 * @property int $id
 * @property string $kind terms, privacy, dpa
 * @property int $version
 * @property array<string, string> $body
 * @property CarbonImmutable|null $published_at
 */
class LegalDocument extends Model
{
    public const KINDS = ['terms', 'privacy', 'dpa'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'body' => 'array', 'published_at' => 'immutable_datetime'];
    }

    public static function current(string $kind): ?self
    {
        return self::query()->where('kind', $kind)->whereNotNull('published_at')->orderByDesc('version')->first();
    }

    public function text(string $locale): string
    {
        return $this->body[$locale] ?? $this->body['en'] ?? (string) (array_values($this->body)[0] ?? '');
    }

    public static function label(string $kind): string
    {
        return match ($kind) {
            'terms' => __('Terms of service'),
            'privacy' => __('Privacy notice'),
            default => __('Data processing agreement'),
        };
    }
}
