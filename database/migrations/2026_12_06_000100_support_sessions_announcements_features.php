<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 300);
            $table->string('mode', 10); // read, write
            $table->string('status', 20); // pending, active, ended, refused
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('extended')->default(false);
            $table->timestamp('ended_at')->nullable();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hotel_id', 'created_at']);
        });

        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->string('support_access', 20)->default('notify'); // notify, ask_first
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->json('title');
            $table->json('body');
            $table->string('level', 20); // info, warning, maintenance
            $table->json('countries')->nullable();
            $table->json('plans')->nullable();
            $table->json('hotels')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->boolean('email')->default(false);
            $table->timestamp('emailed_at')->nullable();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('announcement_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['announcement_id', 'user_id']);
        });

        Schema::create('feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('mode', 10)->default('on'); // on, off, targeted
            $table->json('countries')->nullable();
            $table->json('plans')->nullable();
            $table->json('hotels')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('announcement_dismissals');
        Schema::dropIfExists('announcements');
        Schema::table('hotel_settings', fn (Blueprint $table) => $table->dropColumn('support_access'));
        Schema::dropIfExists('support_sessions');
    }
};
