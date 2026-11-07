<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Middleware;

use App\Domains\Billing\Invoices\Dunning;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * In reduced mode (an invoice unpaid for 14 days), set-up is read-only;
 * day-to-day work goes on: answering guests, bookings and the front desk,
 * orders, payments, and the billing page.
 */
final class ReducedMode
{
    /** @var list<string> */
    private const ALLOWED = [
        'hotel.inbox.*', 'hotel.bookings.*', 'hotel.booking-requests.*', 'hotel.today.*', 'hotel.holds.*',
        'hotel.restaurant.orders.*', 'hotel.restaurant.bookings.*', 'hotel.folio-lines.*', 'hotel.room-blocks.book',
        'hotel.events.payments.*', 'hotel.events.charges.*', 'hotel.my-alerts.*', 'hotel.billing*', 'hotel.email.resend',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! Dunning::isReduced()) {
            return $next($request);
        }

        $name = (string) $request->route()?->getName();

        if (Str::is(self::ALLOWED, $name)) {
            return $next($request);
        }

        throw ValidationException::withMessages(['reduced_mode' => __('An invoice is unpaid: settings are read-only until it is paid. Day-to-day work goes on.')]);
    }
}
