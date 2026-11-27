<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.39.3: a guest's change or cancellation of an existing booking, asked
 * on WhatsApp, waiting for reception on the Today page (testers: the
 * request was only a note in the Inbox, overwritten by the next one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_change_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20); // change, cancellation
            $table->string('status', 20); // pending, applied, declined, withdrawn
            $table->json('stay')->nullable(); // the new stay for a change
            $table->unsignedBigInteger('current_total_minor');
            $table->unsignedBigInteger('new_total_minor')->nullable();
            $table->char('currency', 3);
            $table->string('summary', 300);
            $table->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_change_requests');
    }
};
