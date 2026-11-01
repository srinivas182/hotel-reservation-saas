<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_category_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('base_minor');
            $table->unsignedBigInteger('extra_adult_minor')->default(0);
            $table->unsignedBigInteger('child_minor')->default(0);
            $table->unsignedBigInteger('extra_bed_minor')->default(0);
            $table->string('breakfast_mode', 20)->default('none');
            $table->unsignedBigInteger('breakfast_minor')->default(0);
            $table->unsignedTinyInteger('min_nights')->default(1);
            $table->timestamps();

            $table->index(['hotel_id']);
        });

        Schema::create('rate_overrides', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->json('weekdays')->nullable();
            $table->unsignedBigInteger('rate_minor')->nullable();
            $table->unsignedTinyInteger('min_nights')->nullable();
            $table->boolean('stop_sell')->default(false);
            $table->boolean('closed_to_arrival')->default(false);
            $table->string('label', 100);
            $table->timestamps();

            $table->index(['hotel_id', 'from_date', 'to_date']);
        });

        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->json('child_age_bands')->nullable();
            $table->unsignedSmallInteger('hold_minutes')->default(20);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->dropColumn(['child_age_bands', 'hold_minutes']);
        });
        Schema::dropIfExists('rate_overrides');
        Schema::dropIfExists('category_rates');
    }
};
