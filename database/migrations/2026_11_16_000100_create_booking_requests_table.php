<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('guest_id')->constrained()->restrictOnDelete();
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
            $table->foreignUlid('inventory_hold_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->foreignUlid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'reference']);
            $table->index(['hotel_id', 'status', 'expires_at']);
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('request_hold_minutes')->default(120);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn('request_hold_minutes');
        });
        Schema::dropIfExists('booking_requests');
    }
};
