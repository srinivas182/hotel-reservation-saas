<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->string('mode', 20)->default('off');
            $table->string('privacy_url', 300)->nullable();
            $table->json('human_keywords')->nullable();
            $table->timestamp('readiness_override_at')->nullable();
            $table->string('readiness_override_reason', 300)->nullable();
            $table->foreignUlid('readiness_override_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Hotels that already had the assistant on keep answering automatically.
        DB::table('hotel_assistant_settings')->where('enabled', true)->update(['mode' => 'automatic']);

        Schema::table('messages', function (Blueprint $table): void {
            $table->string('draft_outcome', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn('draft_outcome');
        });
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('readiness_override_by');
            $table->dropColumn(['mode', 'privacy_url', 'human_keywords', 'readiness_override_at', 'readiness_override_reason']);
        });
    }
};
