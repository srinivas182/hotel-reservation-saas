<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint H: the "Chat with us on WhatsApp" kit.
 *
 * chat_sources: each poster, button or link that brings guests to the
 * hotel's WhatsApp, with its short code (added to the pre-typed greeting,
 * so the first message tells us where the guest came from), its greeting
 * and its click count.
 * conversations.chat_source_id: where the conversation started.
 * chat_kits: the hotel's kit settings (button bubble, colour, online
 * hours, logo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('code', 8);
            $table->string('kind', 12);
            $table->string('name', 80);
            $table->string('intent', 12)->default('room');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'code']);
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('chat_source_id')->nullable()->constrained('chat_sources')->nullOnDelete();
            $table->timestamp('chat_source_at')->nullable();
            $table->index(['hotel_id', 'chat_source_id']);
        });

        Schema::create('chat_kits', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('color', 7)->default('#0f5132');
            $table->json('bubble')->nullable();
            $table->string('online_from', 5)->default('07:00');
            $table->string('online_until', 5)->default('22:00');
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['hotel_id', 'chat_source_id']);
            $table->dropConstrainedForeignId('chat_source_id');
            $table->dropColumn('chat_source_at');
        });
        Schema::dropIfExists('chat_kits');
        Schema::dropIfExists('chat_sources');
    }
};
