<?php

declare(strict_types=1);

namespace App\Domains\Status\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An incident or maintenance shown on the status page.
 *
 * @property int $id
 * @property array<string, string> $title
 * @property string $level degraded, outage, maintenance
 * @property list<string> $components
 * @property list<string>|null $countries
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $resolved_at
 */
class StatusNotice extends Model
{
    public const COMPONENTS = ['platform', 'whatsapp', 'ai', 'email', 'payments'];

    public const LEVELS = ['degraded', 'outage', 'maintenance'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'components' => 'array', 'countries' => 'array', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at')->where('starts_at', '<=', now());
    }

    public function concerns(?string $country): bool
    {
        return $this->countries === null || $this->countries === [] || ($country !== null && in_array($country, $this->countries, true));
    }

    public function text(string $locale): string
    {
        return (string) ($this->title[$locale] ?? $this->title['fr'] ?? '');
    }

    /**
     * @return HasMany<StatusUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(StatusUpdate::class)->orderBy('id');
    }

    public static function componentLabel(string $component): string
    {
        return match ($component) {
            'platform' => __('Application'),
            'whatsapp' => __('WhatsApp messages (Meta)'),
            'ai' => __('Assistant (AI)'),
            'email' => __('Emails'),
            default => __('Online payments'),
        };
    }
}
