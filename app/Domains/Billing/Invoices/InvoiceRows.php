<?php

declare(strict_types=1);

namespace App\Domains\Billing\Invoices;

use App\Domains\Billing\Models\Invoice;

/**
 * Invoices of one hotel, as shown on the billing pages.
 */
final class InvoiceRows
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function forHotel(string $hotelId, bool $platform): array
    {
        return Invoice::query()->with('payments')->where('hotel_id', $hotelId)->orderByDesc('issued_on')->orderByDesc('number')->limit(100)->get()
            ->map(fn (Invoice $i): array => [
                'id' => $i->id,
                'number' => $i->number,
                'kind' => $i->kind,
                'issued_on' => $i->issued_on->toDateString(),
                'due_on' => $i->due_on->toDateString(),
                'total' => InvoicePdf::money($i->total_minor, $i->currency),
                'balance' => InvoicePdf::money($i->balance(), $i->currency),
                'balance_minor' => $i->balance(),
                'status' => $i->status(),
                'days_overdue' => $i->daysOverdue(),
                'pdf_url' => $platform ? route('platform.invoices.pdf', $i) : route('hotel.billing.invoice', $i),
                'payments' => $i->payments->map(fn ($p): array => ['amount' => InvoicePdf::money($p->amount_minor, $i->currency), 'method' => $p->method, 'reference' => $p->reference, 'on' => $p->received_on->toDateString()])->values()->all(),
            ])->values()->all();
    }
}
