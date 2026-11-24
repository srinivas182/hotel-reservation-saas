<?php

declare(strict_types=1);

use App\Domains\Conversations\CardNumberMask;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 0.38.0: card numbers guests typed before masking existed are masked in
 * the stored messages and voice-note transcripts too.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('messages')
            ->where('direction', 'in')
            ->where(fn ($q) => $q->whereNotNull('body')->orWhereNotNull('transcript'))
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $body = CardNumberMask::mask($row->body);
                    $transcript = CardNumberMask::mask($row->transcript);

                    if ($body !== $row->body || $transcript !== $row->transcript) {
                        DB::table('messages')->where('id', $row->id)->update(['body' => $body, 'transcript' => $transcript]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Masked numbers cannot, and must not, be restored.
    }
};
