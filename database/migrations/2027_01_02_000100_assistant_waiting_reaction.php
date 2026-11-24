<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 0.37.6: an ⏳ on the guest's message, in the chat itself, while the
 * assistant prepares its answer (WhatsApp shows "typing…" only at the top).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->boolean('waiting_reaction')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('hotel_assistant_settings', function (Blueprint $table): void {
            $table->dropColumn('waiting_reaction');
        });
    }
};
