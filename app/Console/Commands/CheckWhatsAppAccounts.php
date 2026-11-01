<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\AccountStatus;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\WhatsAppConnector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:check')]
#[Description('Check every connected WhatsApp number still works')]
final class CheckWhatsAppAccounts extends Command
{
    public function handle(TenantContext $context, WhatsAppConnector $connector): int
    {
        $failed = 0;

        $context->eachHotel(function () use ($connector, &$failed): void {
            $account = WhatsAppAccount::query()->whereIn('status', [AccountStatus::Connected->value, AccountStatus::ReconnectRequired->value])->first();

            if ($account !== null && ! $connector->check($account)) {
                $failed++;
            }
        });

        $this->components->info("{$failed} WhatsApp connection(s) need attention.");

        return self::SUCCESS;
    }
}
