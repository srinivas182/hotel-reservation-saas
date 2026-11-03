<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('wa_id', 30);
            $table->string('profile_name', 150)->nullable();
            $table->foreignUlid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'wa_id']);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('whatsapp_contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('open');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->index(['hotel_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10);
            $table->string('wamid', 191)->nullable()->unique();
            $table->uuid('client_id')->nullable()->unique();
            $table->string('type', 20);
            $table->text('body')->nullable();
            $table->json('media')->nullable();
            $table->string('template_name', 100)->nullable();
            $table->string('template_language', 10)->nullable();
            $table->string('status', 20);
            $table->timestamp('status_at')->nullable();
            $table->unsignedInteger('error_code')->nullable();
            $table->string('error_text', 500)->nullable();
            $table->foreignUlid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['conversation_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('whatsapp_contacts');
    }
};
