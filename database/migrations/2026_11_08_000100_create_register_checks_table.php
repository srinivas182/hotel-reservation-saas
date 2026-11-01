<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->date('night');
            $table->foreignUlid('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('matches');
            $table->json('rooms');
            $table->string('notes', 1000)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['hotel_id', 'night']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('register_checks');
    }
};
