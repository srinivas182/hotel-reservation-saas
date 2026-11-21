<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint I: each hotel's own back-office colour, and a photo for the
 * sign-in and sign-up screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->string('app_color', 7)->nullable();
        });
        Schema::table('brand_settings', function (Blueprint $table): void {
            $table->string('login_photo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_settings', fn (Blueprint $table) => $table->dropColumn('app_color'));
        Schema::table('brand_settings', fn (Blueprint $table) => $table->dropColumn('login_photo_path'));
    }
};
