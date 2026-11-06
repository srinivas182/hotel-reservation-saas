<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // charge, payment, correction
            $table->string('source', 20); // manual, order
            $table->string('source_id', 40)->nullable();
            $table->string('description', 200);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->bigInteger('amount_minor'); // signed: corrections are negative
            $table->bigInteger('tax_minor')->default(0);
            $table->string('method', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('reason', 300)->nullable();
            $table->foreignId('corrects_line_id')->nullable()->constrained('folio_lines')->nullOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['booking_id', 'created_at']);
            $table->unique(['source', 'source_id']);
        });

        Schema::create('restaurant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('room_charge_enabled')->default(true);
            $table->unsignedBigInteger('room_charge_order_limit_minor')->nullable();
            $table->unsignedBigInteger('room_charge_stay_limit_minor')->nullable();
            $table->boolean('accept_outside_orders')->default(true);
            $table->json('payment_methods')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_settings');
        Schema::dropIfExists('folio_lines');
    }
};
