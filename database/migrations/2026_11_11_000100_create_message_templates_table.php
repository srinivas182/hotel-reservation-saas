<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('starter_key', 60)->nullable();
            $table->string('name', 100);
            $table->string('language', 10);
            $table->string('category', 20);
            $table->text('body');
            $table->json('examples');
            $table->string('status', 20);
            $table->string('meta_template_id', 40)->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'name', 'language']);
            $table->index('meta_template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
