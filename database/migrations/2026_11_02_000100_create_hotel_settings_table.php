<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone_e164', 20)->nullable();
            $table->string('whatsapp_display_e164', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('address_line1', 200)->nullable();
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('map_url', 500)->nullable();
            $table->time('check_in_time')->default('14:00');
            $table->time('check_out_time')->default('12:00');
            $table->time('late_checkout_until')->nullable();
            $table->json('staffed_hours')->nullable();
            $table->json('enabled_locales')->nullable();
            $table->timestamps();
        });

        // Settings rows for hotels created before this migration.
        $now = now();
        DB::table('hotels')->orderBy('id')->select(['id', 'default_locale'])->each(function (object $hotel) use ($now): void {
            DB::table('hotel_settings')->insertOrIgnore([
                'hotel_id' => $hotel->id,
                'enabled_locales' => json_encode([$hotel->default_locale]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_settings');
    }
};
