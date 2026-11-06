<?php

declare(strict_types=1);

namespace App\Domains\Restaurant\Http\Controllers;

use App\Domains\Restaurant\Exceptions\TableNotPossible;
use App\Domains\Restaurant\Models\TableBooking;
use App\Domains\Restaurant\NewTableBooking;
use App\Domains\Restaurant\TableBookingService;
use App\Domains\Restaurant\TableBookingSource;
use App\Domains\Restaurant\TableBookingStatus;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class TableBookingController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TableBookingService $bookings,
    ) {}

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'outlet_id' => ['required', 'string', Rule::exists('outlets', 'id')->where('hotel_id', $this->context->id())],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'covers' => ['required', 'integer', 'min:1', 'max:500'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:25'],
            'source' => ['required', Rule::in([TableBookingSource::Phone->value, TableBookingSource::WalkIn->value])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $booking = $this->bookings->create(new NewTableBooking(
                outletId: (string) $data['outlet_id'],
                date: CarbonImmutable::parse((string) $data['date']),
                time: (string) $data['time'],
                covers: (int) $data['covers'],
                guest: ['first_name' => (string) $data['first_name'], 'last_name' => $data['last_name'] ?? null, 'phone' => $data['phone'] ?? null],
                source: TableBookingSource::from((string) $data['source']),
                notes: $data['notes'] ?? null,
            ), $user);
        } catch (TableNotPossible $e) {
            throw ValidationException::withMessages(['time' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Table booked: :reference.', ['reference' => $booking->reference])]);

        return back();
    }

    public function update(Request $request, TableBooking $tableBooking, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'covers' => ['required', 'integer', 'min:1', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->bookings->change($tableBooking, CarbonImmutable::parse((string) $data['date']), (string) $data['time'], (int) $data['covers'], $data['notes'] ?? null, $user);
        } catch (TableNotPossible $e) {
            throw ValidationException::withMessages(['time' => $e->getMessage()]);
        }

        return back();
    }

    public function status(Request $request, TableBooking $tableBooking, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(TableBookingStatus::class)],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $this->bookings->move($tableBooking, TableBookingStatus::from((string) $data['status']), $user, $data['reason'] ?? null);
        } catch (TableNotPossible $e) {
            throw ValidationException::withMessages(['status' => $e->getMessage()]);
        }

        return back();
    }
}
