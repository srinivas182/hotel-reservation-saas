<?php

declare(strict_types=1);

use App\Domains\Help\HelpDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('category', 30);
            $table->json('title');
            $table->json('body'); // Markdown per language
            $table->json('screens')->nullable(); // Inertia page names this article helps with
            $table->boolean('published')->default(true);
            $table->unsignedInteger('helpful')->default(0);
            $table->unsignedInteger('not_helpful')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('help_searches', function (Blueprint $table): void {
            $table->id();
            $table->string('query', 120);
            $table->string('locale', 5);
            $table->unsignedSmallInteger('results');
            $table->string('source', 20); // site, app, support
            $table->timestamp('created_at')->nullable();

            $table->index(['results', 'created_at']);
        });

        Schema::create('help_feedback', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('help_article_id')->constrained()->cascadeOnDelete();
            $table->boolean('helpful');
            $table->string('comment', 500)->nullable();
            $table->string('locale', 5);
            $table->timestamp('created_at')->nullable();
        });

        $now = now();
        foreach (HelpDefaults::articles() as $i => $a) {
            DB::table('help_articles')->insert([
                'slug' => $a['slug'], 'category' => $a['category'], 'title' => json_encode($a['title']), 'body' => json_encode($a['body']),
                'screens' => json_encode($a['screens']), 'published' => true, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('help_feedback');
        Schema::dropIfExists('help_searches');
        Schema::dropIfExists('help_articles');
    }
};
