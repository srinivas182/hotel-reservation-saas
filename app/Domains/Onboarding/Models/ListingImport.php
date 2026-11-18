<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Models;

use App\Domains\Tenancy\Concerns\BelongsToHotel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One "start from what you already have" import (Sprint A): the sources
 * the owner gave, and the draft found in them.
 *
 * @property string $id
 * @property string $hotel_id
 * @property string|null $created_by
 * @property string $status processing, ready, failed, applied, discarded
 * @property string|null $website_url
 * @property string|null $maps_url
 * @property string|null $pasted_text
 * @property list<array{path: string, name: string, mime: string, size: int}>|null $files
 * @property array<string, mixed>|null $draft
 * @property string|null $error
 * @property array<string, mixed>|null $applied
 * @property CarbonImmutable|null $applied_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class ListingImport extends Model
{
    use BelongsToHotel, HasUlids, Prunable;

    /** Uploaded photos and PDFs are deleted after this many days. */
    public const FILE_DAYS = 7;

    /** Imports a hotel may start per day. */
    public const DAILY_LIMIT = 10;

    protected $fillable = ['created_by', 'status', 'website_url', 'maps_url', 'pasted_text', 'files', 'draft', 'error', 'applied', 'applied_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['files' => 'array', 'draft' => 'array', 'applied' => 'array', 'applied_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The directory of this import's uploads on the local disk.
     */
    public static function directory(string $hotelId, string $importId): string
    {
        return "listing-imports/{$hotelId}/{$importId}";
    }

    public function deleteFiles(): void
    {
        Storage::disk('local')->deleteDirectory(self::directory($this->hotel_id, $this->id));

        if ($this->files !== null) {
            $this->forceFill(['files' => null])->saveQuietly();
        }
    }

    /**
     * Imports older than the file retention are removed with their files;
     * what the owner applied stays in the hotel's data and the audit log.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('created_at', '<', now()->subDays(self::FILE_DAYS));
    }

    protected function pruning(): void
    {
        Storage::disk('local')->deleteDirectory(self::directory($this->hotel_id, $this->id));
    }
}
