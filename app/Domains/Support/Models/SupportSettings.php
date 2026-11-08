<?php

declare(strict_types=1);

namespace App\Domains\Support\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property array<string, int> $response_hours
 * @property bool $ai_drafts
 * @property string|null $whatsapp_hotel_id
 * @property string $whatsapp_template
 */
class SupportSettings extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['response_hours' => 'array', 'ai_drafts' => 'boolean'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['response_hours' => ['default' => 24], 'whatsapp_template' => 'support_reply']);
    }

    public function hoursFor(?string $planCode): int
    {
        return (int) ($this->response_hours[(string) $planCode] ?? $this->response_hours['default'] ?? 24);
    }
}
