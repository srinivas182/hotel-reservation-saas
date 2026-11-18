<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint B: staff answer from WhatsApp. A tap on a button becomes a staff
 * action, carried out after a one-minute undo window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_actions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 30); // which handler, e.g. booking_request
            $table->string('subject_id', 40);
            $table->string('action', 30); // e.g. accept, decline
            $table->string('reason', 300)->nullable();
            $table->string('status', 15)->default('scheduled'); // scheduled, running, done, undone, failed
            $table->string('phone', 20)->nullable();
            $table->timestamp('execute_at');
            $table->timestamp('executed_at')->nullable();
            $table->string('result', 500)->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'kind', 'subject_id']);
            $table->index(['status', 'execute_at']);
        });

        Schema::table('staff_alert_settings', function (Blueprint $table): void {
            $table->boolean('approve_by_whatsapp')->nullable(); // null: on for owners and managers
            $table->timestamp('whatsapp_inbound_at')->nullable(); // last tap: free messages for 24 hours
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('request_escalate_minutes')->default(15); // 0: off
        });

        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->timestamp('escalated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', fn (Blueprint $table) => $table->dropColumn('escalated_at'));
        Schema::table('hotel_assistant_settings', fn (Blueprint $table) => $table->dropColumn('request_escalate_minutes'));
        Schema::table('staff_alert_settings', fn (Blueprint $table) => $table->dropColumn(['approve_by_whatsapp', 'whatsapp_inbound_at']));
        Schema::dropIfExists('staff_actions');
    }
};
