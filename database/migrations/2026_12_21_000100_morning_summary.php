<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C: the morning WhatsApp summary, chosen per person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_alert_settings', function (Blueprint $table): void {
            $table->string('morning_time', 5)->nullable(); // HH:MM hotel time; null: no morning summary
            $table->date('morning_sent_on')->nullable();
            $table->boolean('morning_quiet_days')->default(true); // false: send nothing on a quiet day
        });
    }

    public function down(): void
    {
        Schema::table('staff_alert_settings', fn (Blueprint $table) => $table->dropColumn(['morning_time', 'morning_sent_on', 'morning_quiet_days']));
    }
};
