<?php

declare(strict_types=1);

namespace App\Domains\Events\Http\Controllers;

use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Models\EventQuote;
use App\Domains\Events\Quotes;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The client's private quote page: read the quote and accept it online.
 * The link is the only key; nothing else about the hotel is reachable.
 */
final class PublicQuoteController extends Controller
{
    public function show(string $token, TenantContext $context): View
    {
        [$quote, $hotel] = $this->find($token, $context);

        return $context->run($hotel, function () use ($quote, $hotel): View {
            $quotes = app(Quotes::class);
            $money = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $quote->currency), $hotel->default_locale);
            app()->setLocale($hotel->default_locale);

            return view('events.quote', [
                'hotel' => $hotel,
                'settings' => HotelSettings::current(),
                'quote' => $quote,
                'event' => $quote->event,
                'money' => $money,
                'deposit' => $quotes->depositDue($quote),
                'canAccept' => $quotes->canBeAccepted($quote),
            ]);
        });
    }

    public function accept(Request $request, string $token, TenantContext $context): RedirectResponse
    {
        [$quote, $hotel] = $this->find($token, $context);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'title' => ['nullable', 'string', 'max:150'],
            'terms' => ['accepted'],
        ]);

        $context->run($hotel, function () use ($quote, $data, $request): void {
            try {
                app(Quotes::class)->accept($quote, (string) $data['name'], $data['title'] ?? null, $request->ip(), 'online');
            } catch (EventNotPossible) {
                abort(410);
            }
        });

        return back();
    }

    /**
     * @return array{0: EventQuote, 1: Hotel}
     */
    private function find(string $token, TenantContext $context): array
    {
        abort_if(strlen($token) !== 40, 404);
        $hash = hash('sha256', $token);

        $found = $context->bypass('Public quote by token', fn () => EventQuote::query()->with(['event', 'lines'])->where('token_hash', $hash)->first());
        abort_if($found === null, 404);
        $hotel = Hotel::query()->findOrFail($found->hotel_id);

        return [$found, $hotel];
    }
}
