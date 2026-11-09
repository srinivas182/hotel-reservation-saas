<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S16.4: results of the staging drills (for the launch checklist) and a
 * summary of Content Security Policy reports (to decide when to enforce).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drill_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('drill', 40);
            $table->string('result', 10); // pass, fail
            $table->string('environment', 20);
            $table->text('notes')->nullable();
            $table->json('measures')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['drill', 'created_at']);
        });

        Schema::create('csp_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('directive', 60);
            $table->string('blocked', 191); // host or keyword (inline, eval, data)
            $table->string('page', 191)->nullable(); // path of the first page seen
            $table->unsignedInteger('count')->default(0);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->unique(['directive', 'blocked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csp_reports');
        Schema::dropIfExists('drill_runs');
    }
};
