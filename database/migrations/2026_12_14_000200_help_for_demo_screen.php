<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The demo screen (S17.3a) uses the "Getting started" article for its "?" panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('help_articles')->where('slug', 'demarrer')->first(['id', 'screens']);
        if ($row !== null) {
            $screens = json_decode((string) $row->screens, true) ?: [];
            DB::table('help_articles')->where('id', $row->id)->update(['screens' => json_encode(array_values(array_unique([...$screens, 'hotel/demo'])))]);
        }
    }

    public function down(): void
    {
        $row = DB::table('help_articles')->where('slug', 'demarrer')->first(['id', 'screens']);
        if ($row !== null) {
            $screens = array_values(array_diff(json_decode((string) $row->screens, true) ?: [], ['hotel/demo']));
            DB::table('help_articles')->where('id', $row->id)->update(['screens' => json_encode($screens)]);
        }
    }
};
