<?php

declare(strict_types=1);

namespace App\Domains\Billing\Invoices;

use App\Domains\Billing\Models\Invoice;
use App\Domains\Finance\Money\MoneyFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Money\Money;

/**
 * The invoice as a PDF (also used for the download and the email).
 */
final class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['lines', 'payments']);

        return Pdf::loadView('billing.invoice', ['invoice' => $invoice, 'money' => fn (int $minor): string => self::money($minor, $invoice->currency)])
            ->setPaper('a4')
            ->output();
    }

    public static function money(int $minor, string $currency): string
    {
        // DomPDF's fonts lack the narrow no-break space used by French formats.
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', MoneyFormatter::format(Money::ofMinor($minor, $currency), app()->getLocale()));
    }
}
