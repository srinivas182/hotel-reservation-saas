<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.38.0: a room category's maximum guests can no longer exceed its
 * maximum adults plus children; existing ones are brought down to what was
 * already the real limit, so the Rooms page and the assistant agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('room_categories')
            ->whereRaw('max_occupancy > max_adults + max_children')
            ->update(['max_occupancy' => DB::raw('max_adults + max_children')]);
    }

    public function down(): void
    {
        // The earlier maximum could never be reached; nothing to restore.
    }
};
