<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 30);
            $table->text('credentials');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            $table->string('model', 150);
            $table->string('label', 150);
            $table->json('capabilities');
            $table->string('status', 20)->default('testing');
            // Prices in micro-US dollars (1 USD = 1,000,000).
            $table->unsignedBigInteger('input_cost_per_mtok')->default(0);
            $table->unsignedBigInteger('output_cost_per_mtok')->default(0);
            $table->unsignedBigInteger('audio_cost_per_minute')->default(0);
            $table->unsignedBigInteger('last_eval_run_id')->nullable();
            $table->timestamp('certified_at')->nullable();
            $table->timestamps();

            $table->unique(['ai_provider_id', 'model']);
        });

        Schema::create('ai_platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assistant_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('assistant_fallback_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('transcription_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('transcription_fallback_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->unsignedSmallInteger('timeout_seconds')->default(20);
            $table->boolean('kill_switch')->default(false);
            $table->timestamps();
        });

        Schema::create('hotel_assistant_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('display_name', 100)->nullable();
            $table->string('tone', 20)->default('formal');
            $table->json('languages')->nullable();
            $table->json('handover_messages')->nullable();
            $table->unsignedBigInteger('daily_budget_micro_usd')->default(5_000_000);
            $table->boolean('kill_switch')->default(false);
            $table->date('budget_alerted_on')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('task', 30);
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('audio_seconds')->default(0);
            $table->unsignedBigInteger('cost_micro_usd')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('outcome', 20);
            $table->string('error', 500)->nullable();
            $table->json('guardrails')->nullable();
            $table->longText('request')->nullable();
            $table->longText('response')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['hotel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('hotel_assistant_settings');
        Schema::dropIfExists('ai_platform_settings');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
