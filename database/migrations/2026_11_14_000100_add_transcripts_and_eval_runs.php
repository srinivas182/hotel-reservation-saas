<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->text('transcript')->nullable();
            $table->string('transcript_language', 10)->nullable();
            $table->timestamp('transcribed_at')->nullable();
            $table->string('transcript_error', 300)->nullable();
            $table->string('language', 10)->nullable();
        });

        Schema::create('ai_eval_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('suite', 100);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('passed')->default(0);
            $table->unsignedInteger('safety_total')->default(0);
            $table->unsignedInteger('safety_passed')->default(0);
            $table->boolean('meets_threshold')->default(false);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->json('results')->nullable();
            $table->foreignUlid('started_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_eval_runs');

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['transcript', 'transcript_language', 'transcribed_at', 'transcript_error', 'language']);
        });
    }
};
