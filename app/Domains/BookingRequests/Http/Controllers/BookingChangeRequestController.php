<?php

declare(strict_types=1);

namespace App\Domains\BookingRequests\Http\Controllers;

use App\Domains\BookingRequests\BookingChangeRequest;
use App\Domains\BookingRequests\BookingChangeRequests;
use App\Domains\Bookings\Exceptions\BookingActionNotAllowed;
use App\Domains\Bookings\Exceptions\BookingNotPossible;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;

/**
 * 0.39.3: reception applies or declines a guest's change or cancellation
 * request from the Today page; the guest is told on WhatsApp either way.
 */
final class BookingChangeRequestController extends Controller
{
    public function __construct(private readonly BookingChangeRequests $requests) {}

    public function apply(BookingChangeRequest $changeRequest, #[CurrentUser] User $user): RedirectResponse
    {
        try {
            $this->requests->apply($changeRequest, $user);
        } catch (BookingNotPossible|BookingActionNotAllowed|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['change' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $changeRequest->kind === BookingChangeRequest::CANCELLATION
            ? __('Booking cancelled. The guest is told on WhatsApp.')
            : __('Booking changed. The guest is sent the new details on WhatsApp.')]);

        return back();
    }

    public function decline(Request $request, BookingChangeRequest $changeRequest, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:250']]);

        try {
            $this->requests->decline($changeRequest, $user, trim((string) $data['reason']));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['change' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request declined. The guest is told on WhatsApp.')]);

        return back();
    }
}
