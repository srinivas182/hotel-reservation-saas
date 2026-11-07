<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Billing\Invoices\Invoicing;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('billing:invoice')]
#[Description('Invoice renewals, upgrades and packs not yet invoiced, in every hotel')]
final class IssueInvoices extends Command
{
    public function handle(TenantContext $context, Invoicing $invoicing): int
    {
        $total = 0;
        $context->eachHotel(function () use ($invoicing, &$total): void {
            $total += $invoicing->issueDue() === null ? 0 : 1;
        });
        $this->components->info("{$total} invoice(s) issued.");

        return self::SUCCESS;
    }
}
