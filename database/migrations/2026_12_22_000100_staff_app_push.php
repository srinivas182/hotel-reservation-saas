<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint D: the staff app on the phone. Notifications from the app itself
 * (Web Push, no Meta fee), WhatsApp as the backup, quiet hours per person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint'); // encrypted: it lets anyone send to the phone
            $table->char('endpoint_hash', 64);
            $table->text('p256dh'); // encrypted
            $table->text('auth'); // encrypted
            $table->string('device', 80)->nullable(); // "Android · Chrome", for the person's list
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();

            $table->unique(['hotel_id', 'endpoint_hash']);
            $table->index(['hotel_id', 'user_id']);
        });

        Schema::table('staff_alerts', function (Blueprint $table): void {
            $table->timestamp('opened_at')->nullable(); // the notification was opened (push)
        });

        Schema::table('staff_alert_settings', function (Blueprint $table): void {
            $table->boolean('push_enabled')->default(true);
            $table->string('quiet_from', 5)->nullable(); // HH:MM hotel time
            $table->string('quiet_to', 5)->nullable();
        });

        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->timestamp('last_call_at')->nullable(); // "30 minutes left" alert, sent through quiet hours
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', fn (Blueprint $table) => $table->dropColumn('last_call_at'));
        Schema::table('staff_alert_settings', fn (Blueprint $table) => $table->dropColumn(['push_enabled', 'quiet_from', 'quiet_to']));
        Schema::table('staff_alerts', fn (Blueprint $table) => $table->dropColumn('opened_at'));
        Schema::dropIfExists('push_subscriptions');
    }
};
