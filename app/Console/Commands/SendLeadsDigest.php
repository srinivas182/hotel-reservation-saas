<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Site\Mail\LeadsDigestMail;
use App\Domains\Site\Models\Lead;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

#[Signature('leads:digest')]
#[Description('Email the sales team the new leads and the follow-ups due')]
final class SendLeadsDigest extends Command
{
    public function handle(): int
    {
        $row = fn (Lead $l): array => ['hotel' => $l->hotel_name, 'name' => $l->name, 'whatsapp' => $l->whatsapp, 'status' => $l->status, 'follow_up' => $l->follow_up_on?->toDateString()];
        $new = Lead::query()->where('status', 'new')->where('created_at', '>=', now()->subDay())->get()->map($row)->values()->all();
        $overdue = Lead::query()->whereNotIn('status', ['won', 'lost'])->whereNotNull('follow_up_on')->where('follow_up_on', '<=', now()->toDateString())->get()->map($row)->values()->all();

        if ($new === [] && $overdue === []) {
            $this->components->info('Nothing to send.');

            return self::SUCCESS;
        }

        $recipients = User::query()->whereIn('platform_role', [PlatformRole::Sales->value, PlatformRole::SuperAdmin->value])->whereNull('deactivated_at')->pluck('email');
        foreach ($recipients as $email) {
            Mail::to((string) $email)->queue(new LeadsDigestMail($new, $overdue));
        }
        $this->components->info(count($new).' new, '.count($overdue).' to follow up, sent to '.$recipients->count().'.');

        return self::SUCCESS;
    }
}
