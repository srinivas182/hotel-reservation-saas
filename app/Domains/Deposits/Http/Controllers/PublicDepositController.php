<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Http\Controllers;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Deposits\DepositNotPossible;
use App\Domains\Deposits\DepositPolicy;
use App\Domains\Deposits\Deposits;
use App\Domains\Deposits\Drivers\SimulatedDeposits;
use App\Domains\Deposits\Models\DepositAttempt;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Finance\Money\MoneyFormatter;
use App\Domains\Folio\Folio;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Brick\Money\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * The guest's private payment page (Sprint F). The link is the key: a
 * random 40-character token, of which only a hash is looked up. Plain
 * HTML, light enough for a slow phone connection.
 */
final class PublicDepositController extends Controller
{
    public function show(string $token, TenantContext $context, Request $request): View
    {
        [$link, $hotel] = $this->find($token, $context);

        return $context->run($hotel, function () use ($link, $hotel, $request): View {
            $booking = $link->booking->loadMissing(['guest', 'category']);
            $locale = $booking->guest->preferred_locale ?? $hotel->default_locale;
            app()->setLocale($locale);
            $settings = HotelAssistantSettings::current();
            $deposits = app(Deposits::class);
            $money = fn (int $minor): string => MoneyFormatter::format(Money::ofMinor($minor, $link->currency), $locale);
            $open = $deposits->canPay($link);
            $balance = $link->purpose === 'balance';
            $full = $open && ! $balance && $settings->deposit_allow_full ? $deposits->fullAmount($link) : null;
            $pending = $link->attempts()->where('status', 'pending')->whereNotNull('checkout_url')->where('created_at', '>', now()->subMinutes(30))->exists();
            $hotelSettings = HotelSettings::current();

            return view('deposits.pay', [
                'hotel' => $hotel,
                'hotelSettings' => $hotelSettings,
                'link' => $link,
                'booking' => $booking,
                'token' => $link->token,
                'state' => match (true) {
                    $link->status === 'paid' => 'paid',
                    $open => 'open',
                    default => 'closed',
                },
                'balance' => $balance,
                'pending' => $pending && $link->status !== 'paid',
                'deposit' => $money($link->deposit_minor),
                'full' => $full !== null && $full > $link->deposit_minor ? $money($full) : null,
                'paid' => $link->paid_minor === null ? null : $money($link->paid_minor),
                'left' => $link->status === 'paid' && ($balance = app(Folio::class)->balance($booking)) > 0 ? $money($balance) : null,
                'method' => $link->method === null ? null : RestaurantSettings::methodLabel($link->method),
                'deadline' => $link->expires_at?->setTimezone($hotel->timezone)->settings(['locale' => $locale])->isoFormat('dddd D MMMM, HH:mm'),
                'checkIn' => $booking->check_in->settings(['locale' => $locale])->isoFormat('D MMMM YYYY'),
                'checkOut' => $booking->check_out->settings(['locale' => $locale])->isoFormat('D MMMM YYYY'),
                'room' => (string) $booking->category?->localizedName($locale, $hotel->default_locale),
                'policy' => DepositPolicy::text($settings, $locale),
                'manual' => trim((string) ($settings->deposit_instructions[$locale] ?? $settings->deposit_instructions[$hotel->default_locale] ?? '')),
                'whatsapp' => ($hotelSettings->whatsapp_display_e164 ?? $hotelSettings->phone_e164) === null ? null : 'https://wa.me/'.ltrim((string) ($hotelSettings->whatsapp_display_e164 ?? $hotelSettings->phone_e164), '+'),
                'error' => $request->session()->get('deposit_error'),
            ]);
        });
    }

    public function pay(Request $request, string $token, TenantContext $context): RedirectResponse
    {
        [$link, $hotel] = $this->find($token, $context);
        $data = $request->validate(['choice' => ['required', Rule::in(['deposit', 'full'])]]);

        $url = $context->run($hotel, function () use ($link, $data): ?string {
            try {
                return app(Deposits::class)->start($link, $data['choice'] === 'full' ? 'full' : 'deposit')->checkout_url;
            } catch (DepositNotPossible $e) {
                session()->flash('deposit_error', $e->getMessage());

                return null;
            }
        });

        return $url === null ? to_route('deposits.show', $token) : redirect()->away($url);
    }

    /** The provider sends the payer back here: check, then show the result. */
    public function back(string $token, TenantContext $context): RedirectResponse
    {
        [$link, $hotel] = $this->find($token, $context);

        $context->run($hotel, function () use ($link): void {
            foreach ($link->attempts()->with('account')->where('status', 'pending')->latest()->limit(3)->get() as $attempt) {
                $this->settleQuietly($attempt);
            }
        });

        return to_route('deposits.show', $token);
    }

    /** The pretend checkout of simulation mode. */
    public function simulate(string $reference, TenantContext $context): View
    {
        abort_unless((bool) config('simulation.enabled'), 404);
        [$attempt, $hotel] = $this->attempt($reference, $context);

        return view('deposits.simulate', [
            'hotel' => $hotel,
            'attempt' => $attempt,
            'amount' => MoneyFormatter::format(Money::ofMinor($attempt->amount_minor, $attempt->currency), $hotel->default_locale),
            'methods' => ['wave' => 'Wave', 'orange_money' => 'Orange Money', 'mtn_momo' => 'MTN MoMo', 'moov_money' => 'Moov Money'],
        ]);
    }

    public function simulatePay(Request $request, string $reference, TenantContext $context): RedirectResponse
    {
        abort_unless((bool) config('simulation.enabled'), 404);
        [$attempt, $hotel] = $this->attempt($reference, $context);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['paid', 'failed'])],
            'method' => ['required', Rule::in(['wave', 'orange_money', 'mtn_momo', 'moov_money'])],
        ]);

        SimulatedDeposits::record($attempt->reference, $data['outcome'] === 'paid' ? 'paid' : 'failed', (string) $data['method']);
        $token = $context->run($hotel, fn (): string => $attempt->link->token);

        return to_route('deposits.back', $token);
    }

    /**
     * @return array{0: DepositLink, 1: Hotel}
     */
    private function find(string $token, TenantContext $context): array
    {
        abort_if(strlen($token) !== 40, 404);
        $hash = hash('sha256', $token);

        $found = $context->bypass('Deposit link by token', fn () => DepositLink::query()->with('booking')->where('token_hash', $hash)->first());
        abort_if($found === null, 404);

        return [$found, Hotel::query()->findOrFail($found->hotel_id)];
    }

    /**
     * @return array{0: DepositAttempt, 1: Hotel}
     */
    private function attempt(string $reference, TenantContext $context): array
    {
        $found = $context->bypass('Simulated deposit attempt', fn () => DepositAttempt::query()->where('reference', $reference)->first());
        abort_if($found === null, 404);

        return [$found, Hotel::query()->findOrFail($found->hotel_id)];
    }

    private function settleQuietly(DepositAttempt $attempt): void
    {
        try {
            app(Deposits::class)->settle($attempt);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
