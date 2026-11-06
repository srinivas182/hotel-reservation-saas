<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_spaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->json('capacities');
            $table->json('combines')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('event_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->string('starts_at', 5);
            $table->string('ends_at', 5);
            $table->json('covers')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('event_space_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('event_space_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_block_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price_minor');
            $table->timestamps();

            $table->unique(['event_space_id', 'event_block_id']);
        });

        Schema::create('event_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // package, extra
            $table->json('name');
            $table->json('description')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->string('unit', 20); // per_person, per_unit, per_event
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('event_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['hotel_id', 'year']);
        });

        Schema::create('events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20);
            $table->string('client_name', 120);
            $table->string('company', 150)->nullable();
            $table->string('phone', 25)->nullable();
            $table->string('email', 191)->nullable();
            $table->foreignUlid('guest_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('source', 20);
            $table->date('date');
            $table->foreignId('event_block_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('event_space_id')->nullable()->constrained()->nullOnDelete();
            $table->string('layout', 20)->nullable();
            $table->unsignedSmallInteger('people');
            $table->string('status', 20);
            $table->timestamp('option_until')->nullable();
            $table->boolean('waitlisted')->default(false);
            $table->timestamp('waitlisted_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason', 300)->nullable();
            $table->foreignUlid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['hotel_id', 'reference']);
            $table->index(['hotel_id', 'date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('event_sequences');
        Schema::dropIfExists('event_packages');
        Schema::dropIfExists('event_space_prices');
        Schema::dropIfExists('event_blocks');
        Schema::dropIfExists('event_spaces');
    }
};
