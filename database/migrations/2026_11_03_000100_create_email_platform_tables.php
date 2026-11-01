<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email platform (S1.4): platform-level providers, the email log, the
 * append-only usage ledger, and each hotel's sender identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('driver', 30);
            $table->text('credentials');
            $table->string('from_address');
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_error', 500)->nullable();
            $table->timestamps();

            $table->index(['enabled', 'priority']);
        });

        Schema::create('email_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 20);
            $table->json('recipients');
            $table->string('subject', 255)->nullable();
            $table->foreignId('mail_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->string('provider_message_id', 255)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'created_at']);
            $table->index(['created_at']);
        });

        Schema::create('usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('meter', 50);
            $table->unsignedInteger('quantity');
            $table->string('source_type', 50)->nullable();
            $table->string('source_id', 50)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['hotel_id', 'meter', 'occurred_at']);
            $table->unique(['source_type', 'source_id', 'meter']);
        });

        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->string('email_sender_name', 120)->nullable()->after('map_url');
            $table->string('email_reply_to')->nullable()->after('email_sender_name');
            $table->timestamp('email_reply_to_verified_at')->nullable()->after('email_reply_to');
            $table->char('email_reply_to_token_hash', 64)->nullable()->unique()->after('email_reply_to_verified_at');
            $table->timestamp('email_reply_to_token_expires_at')->nullable()->after('email_reply_to_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->dropUnique(['email_reply_to_token_hash']);
            $table->dropColumn([
                'email_sender_name', 'email_reply_to', 'email_reply_to_verified_at',
                'email_reply_to_token_hash', 'email_reply_to_token_expires_at',
            ]);
        });
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('email_messages');
        Schema::dropIfExists('mail_providers');
    }
};
