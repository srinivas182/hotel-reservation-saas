<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's default language (Platform → Website): null keeps the
 * original behaviour (French website, the visitor's browser in the app).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_settings', function (Blueprint $table): void {
            $table->string('default_locale', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('brand_settings', fn (Blueprint $table) => $table->dropColumn('default_locale'));
    }
};
