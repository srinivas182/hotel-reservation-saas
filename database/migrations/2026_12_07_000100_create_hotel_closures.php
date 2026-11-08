<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('by_role', 20); // owner, platform
            $table->string('reason', 500)->nullable();
            $table->string('previous_status', 20);
            $table->timestamp('closed_at');
            $table->timestamp('delete_after');
            $table->string('export_path', 255)->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->json('reminders')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'reopened_at', 'purged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_closures');
    }
};
