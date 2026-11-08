<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guest notes and booking notes can hold personal details (health, ID,
 * habits): stored encrypted from now on (S16).
 */
return new class extends Migration
{
    private const COLUMNS = ['guests' => 'notes', 'bookings' => 'notes'];

    public function up(): void
    {
        // migration-safety: widens string to text (no data lost; the previous release reads text the same way).
        foreach (self::COLUMNS as $table => $column) {
            Schema::table($table, fn (Blueprint $t) => $t->text($column)->nullable()->change());

            DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(500, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $value = (string) $row->{$column};
                    if ($value !== '' && ! str_starts_with($value, 'eyJpdiI')) {
                        DB::table($table)->where('id', $row->id)->update([$column => Crypt::encryptString($value)]);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $column) {
            DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(500, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    try {
                        DB::table($table)->where('id', $row->id)->update([$column => mb_substr(Crypt::decryptString((string) $row->{$column}), 0, 1000)]);
                    } catch (Throwable) {
                        // Already plain.
                    }
                }
            });
        }
    }
};
