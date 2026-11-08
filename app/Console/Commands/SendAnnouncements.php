<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Announcements\Announcements;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('announcements:send')]
#[Description('Email announcements that have started and ask for an email')]
final class SendAnnouncements extends Command
{
    public function handle(Announcements $announcements): int
    {
        $this->components->info($announcements->sendDue().' announcement email(s) queued.');

        return self::SUCCESS;
    }
}
