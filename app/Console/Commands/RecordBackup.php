<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\GoLive\Readiness;
use App\Domains\Operations\BackupRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Called by docker/backup/backup.sh after each backup.
 */
#[Signature('backup:record {status : success or failure} {--label=} {--file=} {--size=} {--off-site} {--error=}')]
#[Description('Record the result of a backup')]
final class RecordBackup extends Command
{
    public function handle(): int
    {
        $status = (string) $this->argument('status');

        if (! in_array($status, ['success', 'failure'], true)) {
            $this->components->error('Status must be success or failure.');

            return self::INVALID;
        }

        BackupRun::query()->create([
            'kind' => 'backup',
            'status' => $status,
            'label' => $this->option('label'),
            'file' => $this->option('file'),
            'size_bytes' => is_numeric($this->option('size')) ? (int) $this->option('size') : null,
            'off_site' => (bool) $this->option('off-site'),
            'details' => $status === 'failure' ? ['error' => mb_substr((string) $this->option('error'), 0, 300)] : null,
        ]);

        if ($status === 'success') {
            Cache::forever(Readiness::BACKUP_KEY, now()->toIso8601String());
        }

        $this->components->info("Backup {$status} recorded.");

        return self::SUCCESS;
    }
}
