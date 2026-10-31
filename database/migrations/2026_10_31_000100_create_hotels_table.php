<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotels', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('slug', 160)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->char('country_code', 2);
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->string('default_locale', 10)->default('en');
            $table->string('status', 20)->default('trial')->index();
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hotel_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['hotel_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_user');
        Schema::dropIfExists('hotels');
    }
};
