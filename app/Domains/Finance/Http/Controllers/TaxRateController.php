<?php

declare(strict_types=1);

namespace App\Domains\Finance\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Finance\Http\Requests\TaxRateRequest;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Finance\Taxes\TaxCalculator;
use App\Domains\Finance\Taxes\TaxLine;
use App\Domains\Finance\Taxes\TaxRate;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Finance\Taxes\TaxType;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

final class TaxRateController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request, TaxCalculator $calculator): Response
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;
        $rates = TaxRate::query()->orderBy('sort_order')->orderBy('created_at')->get();
        $minorDigits = Money::zero($hotel->currency)->getCurrency()->getDefaultFractionDigits();

        return Inertia::render('hotel/taxes', [
            'currency' => $hotel->currency,
            'minorDigits' => $minorDigits,
            'locales' => HotelSettings::current()->enabled_locales ?? [$locale],
            'defaultLocale' => $locale,
            'rates' => $rates->map(fn (TaxRate $rate): array => [
                'id' => $rate->id,
                'name' => $rate->name,
                'display_name' => $rate->localizedName($locale, $locale),
                'type' => $rate->type->value,
                'type_label' => $rate->type->label(),
                'percentage' => $rate->percentage_bps === null ? null : $rate->percentage_bps / 100,
                'amount' => $rate->amount_minor === null
                    ? null
                    : (string) Money::ofMinor($rate->amount_minor, $hotel->currency)->getAmount(),
                'amount_display' => $rate->amount_minor === null
                    ? null
                    : MoneyFormatter::format(Money::ofMinor($rate->amount_minor, $hotel->currency), $locale),
                'inclusive' => $rate->inclusive,
                'applies_to' => $rate->applies_to,
                'active' => $rate->active,
                'effective_from' => $rate->effective_from?->toDateString(),
                'effective_to' => $rate->effective_to?->toDateString(),
                'sort_order' => $rate->sort_order,
            ])->values(),
            'types' => collect(TaxType::cases())->map(fn (TaxType $type): array => ['value' => $type->value, 'label' => $type->label()])->values(),
            'scopes' => collect(TaxScope::cases())->map(fn (TaxScope $scope): array => ['value' => $scope->value, 'label' => $scope->label()])->values(),
            'preview' => Inertia::optional(fn (): ?array => $this->preview($request, $rates, $calculator)),
            'canManage' => $request->user()?->can('taxes.manage') === true,
        ]);
    }

    public function store(TaxRateRequest $request): RedirectResponse
    {
        $rate = TaxRate::create($request->toAttributes());

        Audit::record('taxes.created', 'Tax rate created', subject: $this->context->hotel(), properties: $this->auditData($rate));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax created.')]);

        return back();
    }

    public function update(TaxRateRequest $request, TaxRate $taxRate): RedirectResponse
    {
        $before = $this->auditData($taxRate);
        $taxRate->update($request->toAttributes());

        Audit::record('taxes.updated', 'Tax rate updated', subject: $this->context->hotel(), properties: [
            'old' => $before,
            'new' => $this->auditData($taxRate),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax updated.')]);

        return back();
    }

    public function destroy(TaxRate $taxRate): RedirectResponse
    {
        $data = $this->auditData($taxRate);
        $taxRate->delete();

        Audit::record('taxes.deleted', 'Tax rate deleted', subject: $this->context->hotel(), properties: $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tax deleted.')]);

        return back();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TaxRate>  $rates
     * @return array<string, mixed>|null
     */
    private function preview(Request $request, $rates, TaxCalculator $calculator): ?array
    {
        $hotel = $this->context->hotel();
        $locale = $hotel->default_locale;

        $input = $request->validate([
            'price' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'nights' => ['required', 'integer', 'min:1', 'max:365'],
            'guests' => ['required', 'integer', 'min:1', 'max:50'],
            'scope' => ['required', 'string'],
        ]);

        $scope = TaxScope::tryFrom((string) $input['scope']) ?? TaxScope::Rooms;

        try {
            $breakdown = $calculator->calculate(
                $rates,
                Money::of((string) $input['price'], $hotel->currency, roundingMode: RoundingMode::HalfUp),
                $scope,
                now($hotel->timezone),
                (int) $input['nights'],
                (int) $input['guests'],
            );
        } catch (Throwable) {
            return null;
        }

        $format = fn (Money $money): string => MoneyFormatter::format($money, $locale);

        return [
            'net' => $format($breakdown->net),
            'lines' => array_map(fn (TaxLine $line): array => [
                'name' => $line->rate->localizedName($locale, $locale),
                'amount' => $format($line->amount),
                'inclusive' => $line->inclusive,
            ], $breakdown->lines),
            'included_tax' => $format($breakdown->includedTax()),
            'added_tax' => $format($breakdown->addedTax()),
            'total' => $format($breakdown->total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditData(TaxRate $rate): array
    {
        return Arr::only($rate->attributesToArray(), [
            'id', 'name', 'type', 'percentage_bps', 'amount_minor', 'inclusive', 'applies_to', 'active', 'effective_from', 'effective_to',
        ]);
    }
}
