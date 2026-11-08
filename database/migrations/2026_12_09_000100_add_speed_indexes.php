<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes found missing in the S16 review of the busiest queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Platform health: "last notification received" per WhatsApp number.
        Schema::table('whatsapp_webhook_events', fn (Blueprint $t) => $t->index(['phone_number_id', 'received_at']));
        // Audit log viewer: filter by event type and date.
        Schema::table('activity_log', fn (Blueprint $t) => $t->index(['event', 'created_at']));
        // Platform home and Operations: subscriptions by status and period end.
        Schema::table('subscriptions', fn (Blueprint $t) => $t->index(['status', 'period_end']));
    }

    public function down(): void
    {
        Schema::table('whatsapp_webhook_events', fn (Blueprint $t) => $t->dropIndex(['phone_number_id', 'received_at']));
        Schema::table('activity_log', fn (Blueprint $t) => $t->dropIndex(['event', 'created_at']));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropIndex(['status', 'period_end']));
    }
};
