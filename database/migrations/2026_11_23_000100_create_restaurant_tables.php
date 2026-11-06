<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->string('type', 20);
            $table->unsignedSmallInteger('seats');
            $table->unsignedSmallInteger('default_duration_minutes')->default(90);
            $table->unsignedSmallInteger('slot_arrival_limit')->nullable();
            $table->unsignedSmallInteger('auto_confirm_max_party')->default(6);
            $table->boolean('auto_confirm')->default(true);
            $table->boolean('active')->default(true);
            $table->string('public_slug', 60);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['hotel_id', 'public_slug']);
        });

        Schema::create('outlet_service_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1 = Monday … 7 = Sunday
            $table->json('name');
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedSmallInteger('last_seating_minutes')->default(60);
            $table->timestamps();

            $table->index(['outlet_id', 'weekday']);
        });

        Schema::create('outlet_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outlet_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('service_period_id')->nullable()->constrained('outlet_service_periods')->cascadeOnDelete();
            $table->string('reason', 200)->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'date']);
        });

        Schema::create('table_booking_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['hotel_id', 'year']);
        });

        Schema::create('table_bookings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('guest_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20);
            $table->date('date');
            $table->string('time', 5);
            $table->unsignedSmallInteger('covers');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('status', 20);
            $table->string('source', 20);
            $table->text('notes')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->string('guest_notified', 20)->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'reference']);
            $table->index(['outlet_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_bookings');
        Schema::dropIfExists('table_booking_sequences');
        Schema::dropIfExists('outlet_closures');
        Schema::dropIfExists('outlet_service_periods');
        Schema::dropIfExists('outlets');
    }
};
