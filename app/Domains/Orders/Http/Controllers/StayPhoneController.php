<?php

declare(strict_types=1);

namespace App\Domains\Orders\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\GuestDirectory;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Orders\Models\StayPhone;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Extra WhatsApp numbers that belong to a stay (they can order and see the
 * room bill), added by reception.
 */
final class StayPhoneController extends Controller
{
    public function store(Request $request, Booking $booking, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:25']]);
        $phone = GuestDirectory::normalisePhone((string) $data['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('Use the international format, for example +2250700000000.')]);
        }

        StayPhone::query()->firstOrCreate(['booking_id' => $booking->id, 'phone' => $phone]);
        Audit::record('bookings.stay_phone_added', 'Phone number added to the stay', subject: $booking, causer: $user, properties: ['phone' => $phone]);

        return back();
    }

    public function destroy(StayPhone $stayPhone, #[CurrentUser] User $user): RedirectResponse
    {
        $booking = Booking::query()->find($stayPhone->booking_id);
        $stayPhone->delete();
        Audit::record('bookings.stay_phone_removed', 'Phone number removed from the stay', subject: $booking, causer: $user, properties: ['phone' => $stayPhone->phone]);

        return back();
    }
}
