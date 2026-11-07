<?php

declare(strict_types=1);

namespace App\Domains\Events\Http\Controllers;

use App\Domains\Events\Models\Event;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Finance\Taxes\TaxScope;
use App\Domains\Folio\Folio;
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
use Illuminate\View\View;

/**
 * An event's account: deposit and other payments, extras on the day,
 * corrections (shared with room bills), and the printable statement.
 */
final class EventAccountController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Folio $folio,
    ) {}

    public function charge(Request $request, Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:200'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'tax_scope' => ['nullable', Rule::enum(TaxScope::class)],
        ]);
        $this->folio->charge($event, trim((string) $data['description']), (int) $data['quantity'], $this->money((string) $data['unit_price']), isset($data['tax_scope']) ? TaxScope::from((string) $data['tax_scope']) : null, $user);

        return back();
    }

    public function pay(Request $request, Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'method' => ['required', Rule::in(RestaurantSettings::current()->methods())],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $this->folio->pay($event, $this->money((string) $data['amount']), (string) $data['method'], $data['reference'] ?? null, $user);

        return back();
    }

    public function statement(Event $event): View
    {
        $hotel = $this->context->hotel();
        $money = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $hotel->currency), $hotel->default_locale);

        return view('folio.statement', [
            'hotel' => $hotel,
            'settings' => HotelSettings::current(),
            'statement' => $this->folio->statement($event),
            'money' => $money,
            'reference' => $event->reference,
            'referenceLabel' => __('Event'),
            'who' => $event->company ?: $event->client_name,
            'when' => $event->date->toDateString(),
        ]);
    }

    private function money(string $amount): Money
    {
        return Money::of($amount, $this->context->hotel()->currency, roundingMode: RoundingMode::HalfUp);
    }
}
