<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_notices', function (Blueprint $table): void {
            $table->id();
            $table->json('title');
            $table->string('level', 15); // degraded, outage, maintenance
            $table->json('components'); // platform, whatsapp, ai, email, payments
            $table->json('countries')->nullable(); // null: everywhere
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable(); // planned end (maintenance)
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('status_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('status_notice_id')->constrained()->cascadeOnDelete();
            $table->json('body');
            $table->string('state', 15); // investigating, identified, monitoring, resolved, scheduled
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('status_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 191)->unique();
            $table->string('locale', 5)->default('fr');
            $table->char('country_code', 2)->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_subscribers');
        Schema::dropIfExists('status_updates');
        Schema::dropIfExists('status_notices');
    }
};
