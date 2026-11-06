<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_phones', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 20);
            $table->timestamps();

            $table->unique(['booking_id', 'phone']);
            $table->index(['hotel_id', 'phone']);
        });

        Schema::table('outlets', function (Blueprint $table): void {
            $table->boolean('orders_enabled')->default(false);
        });

        Schema::table('menu_items', function (Blueprint $table): void {
            $table->boolean('orderable')->default(true);
        });

        Schema::create('order_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['hotel_id', 'year']);
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('outlet_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 20);
            $table->string('status', 20);
            $table->string('source', 20);
            $table->string('delivery', 20);
            $table->string('room_number', 20)->nullable();
            $table->string('location_note', 200)->nullable();
            $table->string('payment', 20);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->text('notes')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'reference']);
            $table->index(['hotel_id', 'status', 'created_at']);
        });

        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 150);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('total_minor');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_sequences');
        Schema::table('menu_items', fn (Blueprint $table) => $table->dropColumn('orderable'));
        Schema::table('outlets', fn (Blueprint $table) => $table->dropColumn('orders_enabled'));
        Schema::dropIfExists('stay_phones');
    }
};
