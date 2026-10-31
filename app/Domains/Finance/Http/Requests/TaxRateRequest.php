<?php

declare(strict_types=1);

namespace App\Domains\Finance\Http\Requests;

use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Finance\Taxes\TaxType;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TaxRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('taxes.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $isPercentage = $this->input('type') === TaxType::Percentage->value;
        $hotel = app(TenantContext::class)->hotel();

        return [
            'name' => ['required', 'array'],
            "name.{$hotel->default_locale}" => ['required', 'string', 'max:80'],
            'name.*' => ['nullable', 'string', 'max:80'],
            'type' => ['required', Rule::enum(TaxType::class)],
            'percentage' => [$isPercentage ? 'required' : 'prohibited', 'nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'amount' => [$isPercentage ? 'prohibited' : 'required', 'nullable', 'numeric', 'gt:0', 'max:100000000'],
            'inclusive' => ['required', 'boolean'],
            'applies_to' => ['required', 'array', 'min:1'],
            'applies_to.*' => [Rule::enum(TaxScope::class)],
            'active' => ['required', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    /**
     * Validated data mapped to TaxRate attributes (basis points, minor units).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $data = $this->validated();
        $hotel = app(TenantContext::class)->hotel();
        $enabled = HotelSettings::current()->enabled_locales ?? [$hotel->default_locale];
        $type = TaxType::from($data['type']);

        return [
            'name' => array_filter(
                array_intersect_key((array) $data['name'], array_flip($enabled)),
                fn ($value): bool => is_string($value) && trim($value) !== '',
            ),
            'type' => $type,
            'percentage_bps' => $type->isPercentage() ? (int) round(((float) $data['percentage']) * 100) : null,
            'amount_minor' => $type->isPercentage()
                ? null
                : Money::of((string) $data['amount'], $hotel->currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt(),
            'inclusive' => (bool) $data['inclusive'],
            'applies_to' => array_values(array_unique((array) $data['applies_to'])),
            'active' => (bool) $data['active'],
            'effective_from' => $data['effective_from'] ?? null,
            'effective_to' => $data['effective_to'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
