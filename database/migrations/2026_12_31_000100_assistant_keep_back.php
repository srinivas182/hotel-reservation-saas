<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms kept for reception (0.36.7): per room category, how many rooms the
 * WhatsApp assistant may not sell, and how many days before arrival they
 * are released to it. Staff always see and sell every room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_categories', function (Blueprint $table): void {
            $table->unsignedTinyInteger('assistant_keep_back')->default(0);
            $table->unsignedTinyInteger('keep_back_release_days')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('room_categories', function (Blueprint $table): void {
            $table->dropColumn(['assistant_keep_back', 'keep_back_release_days']);
        });
    }
};
