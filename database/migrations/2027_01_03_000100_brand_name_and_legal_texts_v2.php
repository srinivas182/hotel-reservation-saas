<?php

declare(strict_types=1);

use App\Domains\Onboarding\LegalDefaults;
use App\Domains\Site\Models\BrandSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 0.37.7: the platform is CNH Global Tech (the working name "Assistant
 * Hôtel" goes), and the legal texts get their full version 2.
 *
 * Both only replace the starting values: a brand name already changed in
 * Platform → Website, or legal texts already republished by the platform
 * team (version 2 or more), are left as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('brand_settings')->where('name', 'Assistant Hôtel')->update(['name' => 'CNH Global Tech', 'updated_at' => $now]);
        Cache::forget(BrandSettings::CACHE_KEY);

        foreach (LegalDefaults::all() as $kind => $body) {
            $latest = DB::table('legal_documents')->where('kind', $kind)->orderByDesc('version')->first();

            if ($latest === null) {
                DB::table('legal_documents')->insert(['kind' => $kind, 'version' => 1, 'body' => json_encode($body), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

                continue;
            }

            // A new install already started from these texts.
            if ((int) $latest->version !== 1 || json_decode((string) $latest->body, true) === $body) {
                continue;
            }

            DB::table('legal_documents')->insert(['kind' => $kind, 'version' => 2, 'body' => json_encode($body), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        // The version 2 texts stay: hotels may already have accepted them,
        // and acceptances point to them. The brand name is a setting.
    }
};
