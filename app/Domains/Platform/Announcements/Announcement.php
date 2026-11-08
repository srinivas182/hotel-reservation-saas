<?php

declare(strict_types=1);

namespace App\Domains\Platform\Announcements;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A banner shown in hotels' screens (optionally emailed to owners).
 *
 * @property int $id
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property string $level info, warning, maintenance
 * @property list<string>|null $countries
 * @property list<string>|null $plans
 * @property list<string>|null $hotels
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property bool $email
 * @property CarbonImmutable|null $emailed_at
 */
class Announcement extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'array', 'body' => 'array', 'countries' => 'array', 'plans' => 'array', 'hotels' => 'array',
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'email' => 'boolean', 'emailed_at' => 'immutable_datetime',
        ];
    }

    public function text(string $field, string $locale): string
    {
        $values = $field === 'title' ? $this->title : $this->body;

        return $values[$locale] ?? $values['fr'] ?? $values['en'] ?? '';
    }

    public function targets(string $hotelId, string $country, ?string $plan): bool
    {
        $any = ($this->countries ?? []) === [] && ($this->plans ?? []) === [] && ($this->hotels ?? []) === [];

        return $any
            || in_array($hotelId, $this->hotels ?? [], true)
            || in_array($country, $this->countries ?? [], true)
            || ($plan !== null && in_array($plan, $this->plans ?? [], true));
    }
}
