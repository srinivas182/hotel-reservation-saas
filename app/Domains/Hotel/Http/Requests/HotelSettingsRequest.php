<?php

declare(strict_types=1);

namespace App\Domains\Hotel\Http\Requests;

use App\Domains\Hotel\Support\Locales;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class HotelSettingsRequest extends FormRequest
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const E164 = '/^\+[1-9]\d{6,14}$/';

    public function authorize(): bool
    {
        return $this->user()?->can('hotel.settings.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        $clean = fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
            ? preg_replace('/[\s\-().]/', '', $value)
            : null;

        $this->merge([
            'phone_e164' => $clean($this->input('phone_e164')),
            'whatsapp_display_e164' => $clean($this->input('whatsapp_display_e164')),
            'currency' => strtoupper((string) $this->input('currency')),
            'enabled_locales' => array_values(array_unique((array) $this->input('enabled_locales', []))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'phone_e164' => ['nullable', 'string', 'regex:'.self::E164],
            'whatsapp_display_e164' => ['nullable', 'string', 'regex:'.self::E164],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:120'],
            'map_url' => ['nullable', 'url:https', 'max:500'],
            'check_in_time' => ['required', 'date_format:H:i'],
            'check_out_time' => ['required', 'date_format:H:i'],
            'late_checkout_until' => ['nullable', 'date_format:H:i', 'after:check_out_time'],
            'staffed_hours' => ['nullable', 'array:'.implode(',', self::DAYS)],
            'staffed_hours.*' => ['array', 'max:3'],
            'staffed_hours.*.*.from' => ['required', 'date_format:H:i'],
            'staffed_hours.*.*.to' => ['required', 'date_format:H:i', $this->intervalEndsAfterStart()],
            'timezone' => ['required', Rule::in(timezone_identifiers_list())],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/', $this->knownCurrency()],
            'default_locale' => ['required', Rule::in(Locales::codes())],
            'enabled_locales' => ['required', 'array', 'min:1'],
            'enabled_locales.*' => [Rule::in(Locales::codes())],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $enabled = (array) $this->input('enabled_locales', []);

            if (! in_array($this->input('default_locale'), $enabled, true)) {
                $validator->errors()->add('enabled_locales', __('The default language must be one of the enabled languages.'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone_e164.regex' => __('Use the international format, for example +2250700000000.'),
            'whatsapp_display_e164.regex' => __('Use the international format, for example +2250700000000.'),
            'late_checkout_until.after' => __('Late check-out must be after the normal check-out time.'),
        ];
    }

    private function intervalEndsAfterStart(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $from = $this->input(str_replace('.to', '.from', $attribute));

            if (is_string($from) && is_string($value) && $value <= $from) {
                $fail(__('Each staffed period must end after it starts.'));
            }
        };
    }

    private function knownCurrency(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            try {
                \Brick\Money\Currency::of((string) $value);
            } catch (\Throwable) {
                $fail(__('Unknown currency code.'));
            }
        };
    }
}
