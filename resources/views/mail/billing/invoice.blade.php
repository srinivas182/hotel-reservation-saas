<x-mail::message>
@if ($kind === 'reminder')
# {{ __('Invoice :number is overdue', ['number' => $invoice->number]) }}

{{ __('Our records show :amount still due on invoice :number, which was due on :date. If you have already paid, thank you, and please send us the payment reference.', ['amount' => $balance, 'number' => $invoice->number, 'date' => $invoice->due_on->toDateString()]) }}
@else
# {{ $invoice->kind === 'credit_note' ? __('Credit note :number', ['number' => $invoice->number]) : __('Invoice :number', ['number' => $invoice->number]) }}

{{ __('Please find attached the document from :issuer.', ['issuer' => $invoice->issuer['name'] ?? '']) }}
@if ($invoice->kind === 'invoice')

{{ __('Amount: :amount, due on :date.', ['amount' => \App\Domains\Billing\Invoices\InvoicePdf::money($invoice->total_minor, $invoice->currency), 'date' => $invoice->due_on->toDateString()]) }}
@endif
@endif

@if (! empty($invoice->issuer['payment_instructions']))
{{ $invoice->issuer['payment_instructions'] }}
@endif

{{ $invoice->issuer['legal_name'] ?? '' }}
</x-mail::message>
