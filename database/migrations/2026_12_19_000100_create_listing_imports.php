<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint A: "Start from what you already have". An import reads the
 * hotel's own website, a Google Maps link, pasted text and photos of a
 * brochure or rate card, and keeps a draft the owner reviews. Nothing in
 * the draft reaches the hotel's data until the owner applies it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('hotel_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 15)->default('processing'); // processing, ready, failed, applied, discarded
            $table->string('website_url', 500)->nullable();
            $table->string('maps_url', 500)->nullable();
            $table->text('pasted_text')->nullable();
            $table->json('files')->nullable(); // [{path, name, mime, size}] on the local disk, deleted after 7 days
            $table->json('draft')->nullable(); // what was found, see ListingDraft
            $table->string('error', 500)->nullable();
            $table->json('applied')->nullable(); // what the owner chose to use
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['hotel_id', 'created_at']);
        });

        // The map pin of the hotel, for directions sent to guests (Sprint G).
        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_settings', function (Blueprint $table): void {
            $table->dropColumn(['latitude', 'longitude']);
        });
        Schema::dropIfExists('listing_imports');
    }
};
