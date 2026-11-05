<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Operations\BackupRun;
use App\Domains\Operations\RestoreVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Called by docker/backup/verify-restore.sh once a backup has been restored
 * into a temporary database. Checks it and records the restore drill.
 */
#[Signature('backup:verify-restored {database : The temporary database holding the restored backup} {--file=}')]
#[Description('Check a restored backup and record the restore drill')]
final class VerifyRestoredBackup extends Command
{
    public function handle(RestoreVerifier $verifier): int
    {
        $base = (string) config('database.default');
        config(['database.connections.restore_check' => [...(array) config("database.connections.{$base}"), 'database' => (string) $this->argument('database')]]);
        DB::purge('restore_check');

        $results = $verifier->verify('restore_check');
        $ok = collect($results)->every(fn (array $r): bool => $r['ok']);

        BackupRun::query()->create([
            'kind' => 'restore_drill',
            'status' => $ok ? 'success' : 'failure',
            'file' => $this->option('file'),
            'details' => ['checks' => $results],
        ]);

        $this->table(['Check', 'OK', 'Detail'], array_map(fn (array $r): array => [$r['check'], $r['ok'] ? 'yes' : 'NO', $r['detail']], $results));

        DB::purge('restore_check');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
