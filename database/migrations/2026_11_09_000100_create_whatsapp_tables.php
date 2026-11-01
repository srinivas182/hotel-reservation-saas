<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('waba_id', 40);
            $table->string('phone_number_id', 40)->unique();
            $table->string('display_phone', 30)->nullable();
            $table->string('verified_name', 150)->nullable();
            $table->string('quality_rating', 20)->nullable();
            $table->string('messaging_limit', 40)->nullable();
            $table->boolean('coexistence')->default(false);
            $table->string('status', 30);
            $table->text('token')->nullable();
            $table->text('pin')->nullable();
            $table->timestamp('token_checked_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignUlid('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->index('waba_id');
        });

        Schema::create('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('received_at');
            $table->string('phone_number_id', 40)->nullable()->index();
            $table->string('waba_id', 40)->nullable()->index();
            $table->string('kind', 60);
            $table->text('payload');
            $table->timestamp('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
