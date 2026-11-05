<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('alert_phone', 20)->nullable();
            $table->json('alerts')->nullable();
            $table->string('summary_time', 5)->nullable();
            $table->json('summary_channels')->nullable();
            $table->date('summary_sent_on')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'user_id']);
        });

        Schema::create('staff_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30);
            $table->string('subject_id', 40)->nullable();
            $table->string('how', 20);
            $table->string('reason', 200)->nullable();
            $table->string('wamid', 191)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['hotel_id', 'created_at']);
            $table->index(['user_id', 'subject_id', 'created_at']);
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('daily_alert_cap')->default(50);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn('daily_alert_cap');
        });
        Schema::dropIfExists('staff_alerts');
        Schema::dropIfExists('staff_alert_settings');
    }
};
