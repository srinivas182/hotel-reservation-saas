<?php

declare(strict_types=1);

namespace App\Domains\Ai\Models;

use App\Domains\Ai\AiDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A platform AI provider account (MCS's key). Platform-level: not owned
 * by a hotel. Credentials are encrypted and never sent to the browser.
 *
 * @property int $id
 * @property string $name
 * @property AiDriver $driver
 * @property array{key?: string, url?: string|null} $credentials
 * @property bool $active
 */
class AiProvider extends Model
{
    protected $fillable = ['name', 'driver', 'credentials', 'active'];

    protected $hidden = ['credentials'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'driver' => AiDriver::class,
            'credentials' => 'encrypted:array',
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<AiModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(AiModel::class);
    }

    /**
     * The name this provider is registered under in laravel/ai's config.
     */
    public function configName(): string
    {
        return 'mcs_provider_'.$this->id;
    }
}
