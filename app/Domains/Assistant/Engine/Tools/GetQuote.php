<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Engine\Tools;

use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Finance\Taxes\TaxBreakdown;
use App\Domains\Finance\Taxes\TaxLine;
use App\Domains\Rates\Quote\QuoteIssue;
use App\Domains\Rates\Quote\QuoteService;
use App\Domains\Rates\Quote\StayRequest;
use App\Domains\Rooms\Models\RoomCategory;
use App\Domains\Tenancy\TenantContext;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Throwable;

/**
 * The exact price of a stay from the quote engine. Every amount it returns
 * is added to the amounts the reply is allowed to state.
 */
final class GetQuote extends AssistantTool
{
    public function name(): string
    {
        return 'get_quote';
    }

    public function description(): string
    {
        return 'Exact price of a stay from the hotel\'s rates, with taxes and any reason it cannot be booked. Always use this before stating any price.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'category_code' => $schema->string()->description('Room category code from list_room_categories')->required(),
            'check_in' => $schema->string()->description('Arrival date, YYYY-MM-DD')->required(),
            'check_out' => $schema->string()->description('Departure date, YYYY-MM-DD')->required(),
            'adults' => $schema->integer()->description('Number of adults')->required(),
            'children_ages' => $schema->array()->items($schema->integer())->description('Ages of children, if any'),
            'rooms' => $schema->integer()->description('Number of rooms, default 1'),
            'breakfast' => $schema->boolean()->description('Whether breakfast is wanted'),
            'extra_bed' => $schema->boolean()->description('Whether an extra bed is wanted'),
        ];
    }

    protected function run(array $arguments): array
    {
        $hotel = app(TenantContext::class)->hotel();
        $category = RoomCategory::query()->where('code', strtoupper((string) ($arguments['category_code'] ?? '')))->first();

        if ($category === null) {
            return ['error' => 'Unknown room category code. Use list_room_categories.'];
        }

        try {
            $checkIn = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['check_in'] ?? ''));
            $checkOut = CarbonImmutable::createFromFormat('!Y-m-d', (string) ($arguments['check_out'] ?? ''));
        } catch (Throwable) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        if ($checkIn === null || $checkOut === null) {
            return ['error' => 'Dates must be YYYY-MM-DD.'];
        }

        $quote = app(QuoteService::class)->quote(new StayRequest(
            categoryId: $category->id,
            checkIn: $checkIn,
            checkOut: $checkOut,
            adults: max(1, (int) ($arguments['adults'] ?? 1)),
            childAges: array_values(array_map(fn ($age): int => max(0, min(17, (int) $age)), (array) ($arguments['children_ages'] ?? []))),
            rooms: max(1, (int) ($arguments['rooms'] ?? 1)),
            breakfast: (bool) ($arguments['breakfast'] ?? false),
            extraBed: (bool) ($arguments['extra_bed'] ?? false),
        ));

        $format = function (Money $money) use ($hotel): string {
            $this->context->allowMoney($money);

            return MoneyFormatter::format($money, $this->context->locale !== '' ? $this->context->locale : $hotel->default_locale);
        };

        $taxes = fn (?TaxBreakdown $b): array => $b === null ? [] : array_map(fn (TaxLine $line): array => [
            'name' => $line->rate->localizedName($this->context->locale, $hotel->default_locale),
            'amount' => $format($line->amount),
            'included_in_price' => $line->inclusive,
        ], $b->lines);

        return [
            'category' => $category->localizedName($this->context->locale, $hotel->default_locale),
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'bookable' => $quote->bookable(),
            'problems' => array_map(fn (QuoteIssue $i): array => ['code' => $i->code, 'message' => $i->message], $quote->issues),
            'nights' => array_map(fn (array $n): array => ['date' => $n['date'], 'price' => $format($n['rate']), 'rooms_free' => $n['available']], $quote->nights),
            'lines' => array_map(fn (array $l): array => ['item' => $l['label'], 'quantity' => $l['quantity'], 'amount' => $format($l['amount'])], $quote->lines),
            'subtotal' => $format($quote->subtotal),
            'taxes' => [...$taxes($quote->roomTaxes), ...$taxes($quote->breakfastTaxes)],
            'total_to_pay' => $format($quote->total),
        ];
    }
}
