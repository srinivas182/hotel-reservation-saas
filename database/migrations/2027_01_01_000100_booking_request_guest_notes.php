<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the guest asked for with a WhatsApp booking request (0.37.0): a
 * pet, a baby cot, a late arrival, an accessible room… Reception sees it
 * before accepting, and it is copied into the booking's notes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->string('guest_notes', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_requests', function (Blueprint $table): void {
            $table->dropColumn('guest_notes');
        });
    }
};
