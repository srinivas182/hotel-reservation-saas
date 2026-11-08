<?php

declare(strict_types=1);

namespace App\Domains\Help\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $slug
 * @property string $category
 * @property array<string, string> $title
 * @property array<string, string> $body
 * @property list<string>|null $screens
 * @property bool $published
 * @property int $helpful
 * @property int $not_helpful
 * @property int $sort_order
 */
class HelpArticle extends Model
{
    public const CATEGORIES = ['start', 'whatsapp', 'assistant', 'bookings', 'setup', 'restaurant', 'events', 'billing', 'team'];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['title' => 'array', 'body' => 'array', 'screens' => 'array', 'published' => 'boolean', 'helpful' => 'integer', 'not_helpful' => 'integer'];
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

    public function excerpt(string $locale, int $length = 160): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($this->html($locale)))), $length);
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'start' => __('Getting started'),
            'whatsapp' => __('WhatsApp'),
            'assistant' => __('Assistant'),
            'bookings' => __('Bookings'),
            'setup' => __('Rooms and rates'),
            'restaurant' => __('Restaurant and bar'),
            'events' => __('Events'),
            'billing' => __('Billing'),
            default => __('Team and security'),
        };
    }
}
