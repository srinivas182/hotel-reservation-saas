<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Launch defaults (S17.3d): support hours on the brand, and support
 * sessions that ask the owner first for new hotels (privacy first).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_settings', function (Blueprint $table): void {
            $table->json('support_hours')->nullable(); // text per language, shown on help and support pages
        });

        Schema::table('hotel_settings', function (Blueprint $table): void {
            // migration-safety: changes only the default for new rows; existing hotels keep their choice.
            $table->string('support_access', 20)->default('ask_first')->change();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_settings', fn (Blueprint $table) => $table->string('support_access', 20)->default('notify')->change());
        Schema::table('brand_settings', fn (Blueprint $table) => $table->dropColumn('support_hours'));
    }
};
