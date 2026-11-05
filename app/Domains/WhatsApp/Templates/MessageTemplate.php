<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Templates;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp message template for one hotel and language. The body uses
 * Meta's numbered placeholders ({{1}}, {{2}}…).
 *
 * @property string $id
 * @property string $hotel_id
 * @property string|null $starter_key
 * @property string $name
 * @property string $language
 * @property string $category UTILITY, MARKETING or AUTHENTICATION
 * @property string $body
 * @property list<string> $examples one example value per placeholder
 * @property TemplateStatus $status
 * @property string|null $meta_template_id
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $submitted_at
 */
class MessageTemplate extends Model
{
    use BelongsToHotel, HasUlids;

    protected $fillable = [
        'starter_key', 'name', 'language', 'category', 'body', 'examples', 'status',
        'meta_template_id', 'rejection_reason', 'submitted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'examples' => 'array',
            'status' => TemplateStatus::class,
            'submitted_at' => 'immutable_datetime',
        ];
    }

    /**
     * Meta rejects template values that are empty or contain line breaks,
     * tabs or long runs of spaces. Zero is a real value and is kept.
     */
    public static function cleanValue(string $value): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return $clean === '' ? '-' : $clean;
    }

    public function placeholders(): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $this->body, $matches);

        return $matches[1] === [] ? 0 : max(array_map('intval', $matches[1]));
    }

    /**
     * The text the guest will see with these values.
     *
     * @param  list<string>  $values
     */
    public function render(array $values): string
    {
        return (string) preg_replace_callback('/\{\{(\d+)\}\}/', fn (array $m): string => $values[(int) $m[1] - 1] ?? $m[0], $this->body);
    }
}
