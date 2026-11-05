<?php

declare(strict_types=1);

namespace App\Domains\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A backup or restore drill, as reported by docker/backup/*.sh. Platform
 * level (one database backup covers every hotel).
 *
 * @property int $id
 * @property string $kind backup, restore_drill
 * @property string $status success, failure
 * @property string|null $label
 * @property string|null $file
 * @property int|null $size_bytes
 * @property bool $off_site
 * @property array<string, mixed>|null $details
 * @property CarbonImmutable|null $created_at
 */
class BackupRun extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['kind', 'status', 'label', 'file', 'size_bytes', 'off_site', 'details'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array', 'off_site' => 'boolean', 'size_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public static function lastSuccess(string $kind): ?self
    {
        return self::query()->where('kind', $kind)->where('status', 'success')->latest('id')->first();
    }

    public static function last(string $kind): ?self
    {
        return self::query()->where('kind', $kind)->latest('id')->first();
    }
}
