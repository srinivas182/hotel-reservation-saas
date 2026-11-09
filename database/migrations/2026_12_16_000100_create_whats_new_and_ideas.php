<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('release_notes', function (Blueprint $table): void {
            $table->id();
            $table->string('version', 20)->nullable();
            $table->json('title');
            $table->json('body'); // Markdown per language
            $table->string('media_path', 255)->nullable(); // picture or short animation
            $table->string('try_path', 255)->nullable(); // where "Try it" goes, e.g. /hotel/demo
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('whats_new_seen_at')->nullable();
            $table->boolean('product_emails')->default(true); // monthly "What's new" digest
        });

        Schema::create('ideas', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->text('body')->nullable();
            $table->string('category', 20);
            $table->string('status', 15)->default('new'); // new, planned, in_progress, done, declined
            $table->unsignedInteger('votes_count')->default(0);
            $table->foreignUlid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reply')->nullable(); // the platform's answer, shown to everyone
            $table->foreignId('merged_into_id')->nullable()->constrained('ideas')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'votes_count']);
        });

        Schema::create('idea_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('idea_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('hotel_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['idea_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idea_votes');
        Schema::dropIfExists('ideas');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['whats_new_seen_at', 'product_emails']));
        Schema::dropIfExists('release_notes');
    }
};
