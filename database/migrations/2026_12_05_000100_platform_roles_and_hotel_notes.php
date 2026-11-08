<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Support" becomes "Operations" (S15).
        DB::table('users')->where('platform_role', 'support')->update(['platform_role' => 'operations']);

        Schema::create('hotel_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->text('body'); // encrypted
            $table->foreignUlid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['hotel_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_notes');
        DB::table('users')->where('platform_role', 'operations')->update(['platform_role' => 'support']);
    }
};
