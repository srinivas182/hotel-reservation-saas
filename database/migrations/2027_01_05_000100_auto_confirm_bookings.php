<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.39.0: WhatsApp booking requests confirmed by the assistant when rooms
 * are free, within the hotel's limits. Off until the hotel turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->boolean('auto_confirm')->default(false);
            $table->unsignedTinyInteger('auto_confirm_max_rooms')->default(3);
            $table->unsignedSmallInteger('auto_confirm_max_nights')->default(14);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn(['auto_confirm', 'auto_confirm_max_rooms', 'auto_confirm_max_nights']);
        });
    }
};
