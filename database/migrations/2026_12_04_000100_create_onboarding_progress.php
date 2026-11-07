<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('steps')->nullable(); // step => {skipped_at?, confirmed_at?}
            $table->json('questionnaire')->nullable(); // S14.3
            $table->timestamp('golive_requested_at')->nullable();
            $table->timestamp('golive_decided_at')->nullable();
            $table->string('golive_decision', 20)->nullable();
            $table->string('golive_reason', 300)->nullable();
            $table->json('nudges_sent')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_progress');
    }
};
