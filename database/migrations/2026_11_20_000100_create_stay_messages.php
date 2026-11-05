<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stay_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('how', 20);
            $table->string('reason', 100)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['booking_id', 'kind']);
            $table->index(['hotel_id', 'created_at']);
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->json('stay_messages')->nullable();
            $table->string('review_url', 300)->nullable();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestamp('whatsapp_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_consent_at');
        });
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn(['stay_messages', 'review_url']);
        });
        Schema::dropIfExists('stay_messages');
    }
};
