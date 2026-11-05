<?php

declare(strict_types=1);

namespace App\Domains\Operations;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Checks a database restored from a backup: every migration is present,
 * the core tables have data, and encrypted values can be read with this
 * application's key (a backup restored with the wrong key is useless).
 */
final class RestoreVerifier
{
    /**
     * @return list<array{check: string, ok: bool, detail: string}>
     */
    public function verify(string $connection): array
    {
        $db = DB::connection($connection);
        $results = [];

        try {
            $migrations = (int) $db->table('migrations')->count();
            $expected = count(File::glob(database_path('migrations/*.php')));
            $results[] = ['check' => 'migrations', 'ok' => $migrations >= $expected, 'detail' => "{$migrations} of {$expected} migrations"];
        } catch (Throwable $e) {
            return [['check' => 'database', 'ok' => false, 'detail' => mb_substr($e->getMessage(), 0, 200)]];
        }

        foreach (['hotels', 'users', 'hotel_settings'] as $table) {
            $count = (int) $db->table($table)->count();
            $results[] = ['check' => "rows:{$table}", 'ok' => $count > 0, 'detail' => "{$count} rows"];
        }

        foreach (['bookings', 'messages', 'guests'] as $table) {
            $results[] = ['check' => "rows:{$table}", 'ok' => true, 'detail' => $db->table($table)->count().' rows'];
        }

        $sample = $db->table('messages')->whereNotNull('body')->value('body')
            ?? $db->table('whatsapp_accounts')->whereNotNull('token')->value('token');

        if (is_string($sample)) {
            try {
                Crypt::decryptString($sample);
                $results[] = ['check' => 'encrypted_data', 'ok' => true, 'detail' => 'readable with this application key'];
            } catch (DecryptException) {
                $results[] = ['check' => 'encrypted_data', 'ok' => false, 'detail' => 'NOT readable with this application key'];
            }
        } else {
            $results[] = ['check' => 'encrypted_data', 'ok' => true, 'detail' => 'no encrypted rows to check yet'];
        }

        return $results;
    }
}
