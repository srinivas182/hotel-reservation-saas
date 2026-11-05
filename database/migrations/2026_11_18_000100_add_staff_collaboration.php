<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignUlid('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('holding_sent_at')->nullable();

            $table->index(['hotel_id', 'assigned_to']);
            $table->index(['mode', 'waiting_since']);
        });

        Schema::create('conversation_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->text('translation')->nullable();
            $table->string('translation_language', 10)->nullable();
        });

        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('silence_minutes')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn('silence_minutes');
        });
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['translation', 'translation_language']);
        });
        Schema::dropIfExists('conversation_notes');
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['hotel_id', 'assigned_to']);
            $table->dropIndex(['mode', 'waiting_since']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['assigned_at', 'waiting_since', 'holding_sent_at']);
        });
    }
};
