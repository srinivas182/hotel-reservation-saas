<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Availability (S2.3).
 *
 * inventory_days: one row per category and night. It is the row that hold
 * and booking writers lock, and it stores what is not derived elsewhere:
 * rooms held back from sale, stop-sell, and booked rooms (from S3).
 * inventory_holds: temporary holds while a booking is in progress. Active,
 * unexpired holds are counted live, so expiry takes effect immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_category_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedSmallInteger('held_back')->default(0);
            $table->unsignedSmallInteger('booked')->default(0);
            $table->boolean('stop_sell')->default(false);
            $table->timestamps();

            $table->unique(['room_category_id', 'date']);
            $table->index(['hotel_id', 'date']);
        });

        Schema::create('inventory_holds', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_category_id')->constrained()->restrictOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('rooms');
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at');
            $table->string('source_type', 50)->default('manual');
            $table->string('source_id', 50)->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hotel_id', 'status', 'expires_at']);
            $table->index(['room_category_id', 'status', 'check_in', 'check_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_holds');
        Schema::dropIfExists('inventory_days');
    }
};
