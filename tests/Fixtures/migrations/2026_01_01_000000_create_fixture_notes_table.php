<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('body');
            $table->timestamps();

            $table->index(['hotel_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_notes');
    }
};
