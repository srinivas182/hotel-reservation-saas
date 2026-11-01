<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('code', 10);
            $table->json('name');
            $table->json('description')->nullable();
            $table->unsignedTinyInteger('standard_occupancy')->default(2);
            $table->unsignedTinyInteger('max_adults')->default(2);
            $table->unsignedTinyInteger('max_children')->default(0);
            $table->unsignedTinyInteger('max_occupancy')->default(2);
            $table->boolean('extra_bed_allowed')->default(false);
            $table->json('amenities')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['hotel_id', 'code']);
            $table->index(['hotel_id', 'active', 'sort_order']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_category_id')->constrained()->restrictOnDelete();
            $table->string('number', 20);
            $table->string('floor', 20)->nullable();
            $table->string('status', 20)->default('in_service');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'number']);
            $table->index(['hotel_id', 'room_category_id', 'status']);
        });

        Schema::create('room_closures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_id')->constrained()->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('reason', 200)->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'from_date', 'to_date']);
            $table->index(['room_id', 'from_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_closures');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('room_categories');
    }
};
