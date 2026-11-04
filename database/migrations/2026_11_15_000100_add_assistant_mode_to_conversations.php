<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('mode', 20)->default('assistant');
            $table->boolean('needs_attention')->default(false);
            $table->string('handover_reason', 250)->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->ulid('last_answered_message_id')->nullable();
            $table->timestamp('staff_replied_at')->nullable();

            $table->index(['hotel_id', 'needs_attention']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['hotel_id', 'needs_attention']);
            $table->dropColumn(['mode', 'needs_attention', 'handover_reason', 'handed_over_at', 'last_answered_message_id', 'staff_replied_at']);
        });
    }
};
