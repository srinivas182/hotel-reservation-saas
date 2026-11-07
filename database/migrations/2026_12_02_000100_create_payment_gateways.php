<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Online payment providers set by the platform (keys encrypted).
        Schema::create('payment_gateways', function (Blueprint $table): void {
            $table->id();
            $table->string('driver', 20); // paystack, flutterwave, cinetpay, paydunya
            $table->string('name', 80);
            $table->string('mode', 10)->default('test'); // test, live
            $table->text('credentials'); // encrypted JSON
            $table->json('currencies');
            $table->boolean('active')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_checkouts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_gateway_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20); // invoice, pack
            $table->foreignUlid('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reference', 64)->unique();
            $table->string('provider_token', 191)->nullable()->index();
            $table->string('status', 20); // pending, paid, failed
            $table->text('checkout_url')->nullable();
            $table->string('failure', 300)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_checkouts');
        Schema::dropIfExists('payment_gateways');
    }
};
