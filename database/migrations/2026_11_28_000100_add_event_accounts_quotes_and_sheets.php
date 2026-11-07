<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An account belongs to a stay or to an event. Existing room-bill
        // lines keep their booking and are not moved or rewritten.
        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->foreignUlid('event_id')->nullable()->after('booking_id')->constrained()->cascadeOnDelete();
            $table->index(['event_id', 'created_at']);
        });
        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->ulid('booking_id')->nullable()->change();
        });

        Schema::create('event_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('deposit_percent')->default(30);
            $table->unsignedSmallInteger('option_days')->default(7);
            $table->unsignedSmallInteger('quote_valid_days')->default(14);
            $table->json('terms')->nullable();
            $table->timestamps();
        });

        Schema::create('event_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 20); // draft, sent, accepted, superseded
            $table->date('valid_until');
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('discount_minor')->default(0);
            $table->string('discount_reason', 300)->nullable();
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->text('terms')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_name', 150)->nullable();
            $table->string('accepted_title', 150)->nullable();
            $table->string('accepted_ip', 45)->nullable();
            $table->string('accepted_via', 20)->nullable(); // online, staff
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['event_id', 'version']);
        });

        Schema::create('event_quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_quote_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // space, package, extra, free
            $table->string('description', 200);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('total_minor');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('event_sheets', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('content');
            $table->boolean('after_confirmation')->default(false);
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_sheets');
        Schema::dropIfExists('event_quote_lines');
        Schema::dropIfExists('event_quotes');
        Schema::dropIfExists('event_settings');
        Schema::table('folio_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('event_id');
        });
    }
};
