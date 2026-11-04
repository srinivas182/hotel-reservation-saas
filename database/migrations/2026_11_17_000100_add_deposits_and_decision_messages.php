<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->boolean('deposit_required')->default(false);
            $table->unsignedTinyInteger('deposit_percent')->default(30);
            $table->unsignedSmallInteger('deposit_hours')->default(24);
            $table->json('deposit_instructions')->nullable();
            $table->json('decision_messages')->nullable();
        });

        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->string('guest_notified', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->dropColumn('guest_notified');
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn(['deposit_required', 'deposit_percent', 'deposit_hours', 'deposit_instructions', 'decision_messages']);
        });
    }
};
