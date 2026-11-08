<?php

declare(strict_types=1);

namespace App\Domains\Site\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The product's brand (one row): name, colours, logo, support contacts and
 * website analytics. Used across the app, emails, PDFs and the website.
 *
 * @property int $id
 * @property string $name
 * @property array<string, string>|null $tagline
 * @property string $primary_color
 * @property string|null $logo_path
 * @property string|null $support_email
 * @property string|null $support_whatsapp
 * @property string|null $analytics_domain
 * @property string|null $analytics_script
 */
class BrandSettings extends Model
{
    public const CACHE_KEY = 'brand-settings';

    protected $table = 'brand_settings';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tagline' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            Cache::forget(self::CACHE_KEY);
            self::applyToConfig();
        });
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate([], ['name' => (string) config('app.name')]);
    }

    /**
     * The brand names the app in screens, emails and PDFs.
     */
    public static function applyToConfig(): void
    {
        $brand = self::cached();

        if ($brand !== null) {
            config(['app.name' => $brand['name'], 'mail.from.name' => $brand['name']]);
        }
    }

    /**
     * The brand as plain values, cached; null before the table exists.
     *
     * @return array{name: string, tagline: array<string, string>, color: string, logo: string|null, support_email: string|null, support_whatsapp: string|null, analytics_domain: string|null, analytics_script: string|null}|null
     */
    public static function cached(): ?array
    {
        try {
            return Cache::remember(self::CACHE_KEY, 300, function (): ?array {
                if (! Schema::hasTable('brand_settings')) {
                    return null;
                }
                $b = self::query()->first();

                return $b === null ? null : [
                    'name' => $b->name, 'tagline' => $b->tagline ?? [], 'color' => $b->primary_color,
                    'logo' => $b->logo_path === null ? null : route('brand.logo', ['v' => substr(md5((string) $b->updated_at), 0, 8)]),
                    'support_email' => $b->support_email, 'support_whatsapp' => $b->support_whatsapp,
                    'analytics_domain' => $b->analytics_domain, 'analytics_script' => $b->analytics_script,
                ];
            });
        } catch (\Throwable) {
            return null;
        }
    }
}
