<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('phone_e164', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('preferred_locale', 10)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('whatsapp_opt_in')->default(false);
            $table->timestamp('whatsapp_opt_in_at')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('marketing_opt_in_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['hotel_id', 'phone_e164']);
            $table->index(['hotel_id', 'last_name', 'first_name']);
        });

        Schema::create('bookings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30);
            $table->foreignUlid('guest_id')->constrained()->restrictOnDelete();
            $table->string('channel', 20);
            $table->string('external_reference', 100)->nullable();
            $table->string('group_label', 100)->nullable();
            $table->string('status', 20);
            $table->foreignUlid('room_category_id')->constrained()->restrictOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('rooms');
            $table->unsignedSmallInteger('adults');
            $table->json('children_ages');
            $table->boolean('breakfast')->default(false);
            $table->boolean('extra_bed')->default(false);
            $table->json('price_snapshot');
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->boolean('price_overridden')->default(false);
            $table->string('override_reason', 300)->nullable();
            $table->string('payment_note', 500)->nullable();
            $table->string('notes', 2000)->nullable();
            $table->timestamp('tentative_until')->nullable();
            $table->foreignUlid('inventory_hold_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignUlid('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignUlid('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamp('no_show_at')->nullable();
            $table->foreignUlid('no_show_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'reference']);
            $table->index(['hotel_id', 'status', 'check_in']);
            $table->index(['hotel_id', 'check_in', 'check_out']);
            $table->index(['guest_id']);
        });

        Schema::create('booking_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['booking_id', 'position']);
            $table->index(['hotel_id', 'room_id']);
        });

        Schema::create('booking_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['hotel_id', 'year']);
        });

        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->string('booking_prefix', 6)->nullable();
            $table->unsignedSmallInteger('tentative_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->dropColumn(['booking_prefix', 'tentative_hours']);
        });
        Schema::dropIfExists('booking_sequences');
        Schema::dropIfExists('booking_rooms');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('guests');
    }
};
