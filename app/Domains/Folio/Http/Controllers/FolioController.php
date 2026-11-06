<?php

declare(strict_types=1);

namespace App\Domains\Folio\Http\Controllers;

use App\Domains\Bookings\Models\Booking;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Folio\Folio;
use App\Domains\Folio\FolioLine;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class FolioController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Folio $folio,
    ) {}

    public function charge(Request $request, Booking $booking, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'tax_scope' => ['nullable', Rule::enum(TaxScope::class)],
        ]);

        $this->folio->charge(
            $booking,
            trim((string) $data['description']),
            (int) $data['quantity'],
            $this->money((string) $data['unit_price']),
            isset($data['tax_scope']) ? TaxScope::from((string) $data['tax_scope']) : null,
            $user,
        );

        return back();
    }

    public function pay(Request $request, Booking $booking, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'method' => ['required', Rule::in(RestaurantSettings::current()->methods())],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->folio->pay($booking, $this->money((string) $data['amount']), (string) $data['method'], $data['reference'] ?? null, $user);

        return back();
    }

    public function correct(Request $request, FolioLine $line, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($user->can($line->type === 'payment' ? 'folio.payments' : 'folio.charge'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);

        try {
            $this->folio->correct($line, trim((string) $data['reason']), $user);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reason' => __('This line has already been corrected.')]);
        }

        return back();
    }

    /**
     * Printable statement of account (not a tax invoice).
     */
    public function statement(Booking $booking): View
    {
        $hotel = $this->context->hotel();
        $booking->load(['guest', 'category', 'bookingRooms.room']);
        $statement = $this->folio->statement($booking);
        $money = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $booking->currency ?? $hotel->currency), $hotel->default_locale);

        return view('folio.statement', [
            'hotel' => $hotel,
            'settings' => HotelSettings::current(),
            'booking' => $booking,
            'statement' => $statement,
            'money' => $money,
        ]);
    }

    private function money(string $amount): Money
    {
        return Money::of($amount, $this->context->hotel()->currency, roundingMode: RoundingMode::HalfUp);
    }
}
