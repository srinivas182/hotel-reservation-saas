<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Http\Requests;

use App\Domains\Bookings\BookingChannel;
use App\Domains\Bookings\BookingStatus;
use App\Domains\Hotel\Support\Locales;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bookings.create') === true;
    }

    protected function prepareForValidation(): void
    {
        $ages = array_values(array_filter(
            array_map('trim', explode(',', (string) $this->input('children', ''))),
            fn (string $age): bool => $age !== '',
        ));

        $this->merge(['children_ages' => $ages]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hotelId = app(TenantContext::class)->id();
        $override = $this->filled('override_total');

        return [
            'room_category_id' => ['required', 'string', Rule::exists('room_categories', 'id')->where('hotel_id', $hotelId)],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],
            'adults' => ['required', 'integer', 'min:1', 'max:40'],
            'children_ages' => ['array', 'max:20'],
            'children_ages.*' => ['integer', 'between:0,17'],
            'rooms' => ['required', 'integer', 'min:1', 'max:20'],
            'breakfast' => ['required', 'boolean'],
            'extra_bed' => ['required', 'boolean'],
            'status' => ['required', Rule::in([BookingStatus::Confirmed->value, BookingStatus::Tentative->value])],
            'channel' => ['required', Rule::enum(BookingChannel::class)],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'group_label' => ['nullable', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^\+?[0-9\s\-().]{7,24}$/'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'preferred_locale' => ['nullable', Rule::in(Locales::codes())],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_note' => ['nullable', 'string', 'max:500'],
            'override_total' => [$override ? 'required' : 'nullable', 'numeric', 'min:0', 'max:1000000000'],
            'override_reason' => [$override ? 'required' : 'nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => __('Use the international format, for example +2250700000000.'),
            'override_reason.required' => __('Give a reason for changing the price.'),
        ];
    }
}
