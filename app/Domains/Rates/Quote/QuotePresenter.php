<?php

declare(strict_types=1);

namespace App\Domains\Rates\Quote;

use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Finance\Taxes\TaxBreakdown;
use App\Domains\Finance\Taxes\TaxLine;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Runs a quote from a "Try a stay" form and formats it for the screen.
 */
final class QuotePresenter
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly QuoteService $quotes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fromRequest(Request $request): array
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;

        $input = $request->validate([
            'category' => ['required', 'string'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d'],
            'adults' => ['required', 'integer', 'min:1', 'max:40'],
            'children' => ['nullable', 'string', 'max:100'],
            'rooms' => ['required', 'integer', 'min:1', 'max:20'],
            'breakfast' => ['nullable', 'boolean'],
            'extra_bed' => ['nullable', 'boolean'],
        ]);

        $ages = array_values(array_filter(array_map('trim', explode(',', (string) ($input['children'] ?? ''))), fn (string $age): bool => $age !== ''));

        $quote = $this->quotes->quote(new StayRequest(
            categoryId: (string) $input['category'],
            checkIn: CarbonImmutable::parse((string) $input['check_in'], $hotel->timezone),
            checkOut: CarbonImmutable::parse((string) $input['check_out'], $hotel->timezone),
            adults: (int) $input['adults'],
            childAges: array_map(fn (string $age): int => max(0, min(17, (int) $age)), $ages),
            rooms: (int) $input['rooms'],
            breakfast: (bool) ($input['breakfast'] ?? false),
            extraBed: (bool) ($input['extra_bed'] ?? false),
        ));

        $format = fn (Money $money): string => MoneyFormatter::format($money, $locale);
        $taxLines = fn (?TaxBreakdown $breakdown): array => $breakdown === null ? [] : array_map(fn (TaxLine $line): array => [
            'name' => $line->rate->localizedName($locale, $locale),
            'amount' => $format($line->amount),
            'inclusive' => $line->inclusive,
        ], $breakdown->lines);

        return [
            'bookable' => $quote->bookable(),
            'issues' => array_map(fn (QuoteIssue $issue): string => $issue->message, $quote->issues),
            'nights' => array_map(fn (array $night): array => [
                'date' => $night['date'],
                'rate' => $format($night['rate']),
                'label' => $night['label'],
                'available' => $night['available'],
            ], $quote->nights),
            'lines' => array_map(fn (array $line): array => [
                'label' => $line['label'],
                'quantity' => $line['quantity'],
                'amount' => $format($line['amount']),
            ], $quote->lines),
            'subtotal' => $format($quote->subtotal),
            'taxes' => [...$taxLines($quote->roomTaxes), ...$taxLines($quote->breakfastTaxes)],
            'total' => $format($quote->total),
        ];
    }
}
