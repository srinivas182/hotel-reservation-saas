<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

#[Signature('push:keys {--show : Only print the lines, do not touch .env}')]
#[Description('Make the VAPID key pair for staff app notifications and add it to .env if missing')]
final class PushKeys extends Command
{
    public function handle(): int
    {
        if (filled(config('webpush.public_key')) && ! $this->option('show')) {
            $this->components->info('VAPID keys are already set. Changing them makes every phone subscribe again; edit .env by hand if you really must.');

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();
        $lines = "VAPID_PUBLIC_KEY={$keys['publicKey']}\nVAPID_PRIVATE_KEY={$keys['privateKey']}";

        if ($this->option('show')) {
            $this->line($lines);

            return self::SUCCESS;
        }

        $env = base_path('.env');
        if (! is_writable($env)) {
            $this->components->error('.env cannot be written. Add these lines yourself:');
            $this->line($lines);

            return self::FAILURE;
        }

        file_put_contents($env, rtrim((string) file_get_contents($env))."\n\n# Staff app notifications (Sprint D)\n{$lines}\n");
        $this->components->info('VAPID keys added to .env. Clear the config cache (php artisan config:cache) to use them.');

        return self::SUCCESS;
    }
}
