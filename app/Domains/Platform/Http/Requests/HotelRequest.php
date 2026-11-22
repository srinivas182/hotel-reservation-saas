<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Requests;

use App\Domains\Tenancy\Enums\HotelStatus;
use App\Domains\Tenancy\Models\Hotel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and editing a hotel in the platform admin.
 */
final class HotelRequest extends FormRequest
{
    /**
     * Supported interface and guest languages (extended in S1).
     */
    public const LOCALES = ['en', 'fr'];

    public function authorize(): bool
    {
        $hotel = $this->route('hotel');

        return $hotel instanceof Hotel
            ? $this->user()?->can('update', $hotel) === true
            : $this->user()?->can('create', Hotel::class) === true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => strtoupper((string) $this->input('country_code')),
            'currency' => strtoupper((string) $this->input('currency')),
            'slug' => $this->filled('slug') ? strtolower((string) $this->input('slug')) : null,
            'owner_email' => $this->filled('owner_email') ? strtolower((string) $this->input('owner_email')) : null,
        ]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string|\Illuminate\Validation\Rules\In|\Illuminate\Validation\Rules\Unique|\Illuminate\Validation\Rules\Enum|\Illuminate\Validation\Rules\Exists>>
     */
    public function rules(): array
    {
        $hotel = $this->route('hotel');

        return [
            'name' => ['required', 'string', 'max:150'],
            'slug' => [
                'nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $hotel instanceof Hotel ? Rule::unique('hotels', 'slug')->ignore($hotel->getKey()) : Rule::unique('hotels', 'slug'),
            ],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'default_locale' => ['required', Rule::in(self::LOCALES)],
            'status' => [$hotel instanceof Hotel ? 'required' : 'nullable', Rule::enum(HotelStatus::class)],
            // 0.31.3: a new e-mail creates the owner's account (name and password below).
            'owner_email' => ['nullable', 'email:rfc', 'max:255'],
            'owner_first_name' => ['nullable', 'string', 'max:100'],
            'owner_last_name' => ['nullable', 'string', 'max:100'],
            'owner_password' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => __('Use lowercase letters, numbers and single hyphens only.'),
        ];
    }
}
