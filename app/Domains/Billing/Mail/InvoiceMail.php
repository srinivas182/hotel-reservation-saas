<?php

declare(strict_types=1);

namespace App\Domains\Billing\Mail;

use App\Domains\Billing\Invoices\InvoicePdf;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Messaging\Email\Concerns\HasEmailCategory;
use App\Domains\Messaging\Email\EmailCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * An invoice (with its PDF) or a payment reminder, from the issuing company.
 */
final class InvoiceMail extends Mailable
{
    use HasEmailCategory, Queueable;

    public function __construct(public readonly string $invoiceId, public readonly string $kind) {}

    protected function emailCategory(): EmailCategory
    {
        return EmailCategory::System;
    }

    public function envelope(): Envelope
    {
        $invoice = Invoice::query()->findOrFail($this->invoiceId);

        return new Envelope(subject: $this->kind === 'reminder'
            ? (string) __('Reminder: invoice :number is overdue', ['number' => $invoice->number])
            : (string) __(':kind :number from :issuer', ['kind' => $invoice->kind === 'credit_note' ? __('Credit note') : __('Invoice'), 'number' => $invoice->number, 'issuer' => $invoice->issuer['name'] ?? '']));
    }

    public function content(): Content
    {
        $invoice = Invoice::query()->findOrFail($this->invoiceId);

        return new Content(markdown: 'mail.billing.invoice', with: ['invoice' => $invoice, 'kind' => $this->kind, 'balance' => InvoicePdf::money($invoice->balance(), $invoice->currency)]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $invoice = Invoice::query()->findOrFail($this->invoiceId);

        return [Attachment::fromData(fn (): string => app(InvoicePdf::class)->render($invoice), $invoice->number.'.pdf')->withMime('application/pdf')];
    }
}
