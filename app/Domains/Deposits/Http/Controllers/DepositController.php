<?php

declare(strict_types=1);

namespace App\Domains\Deposits\Http\Controllers;

use App\Domains\Ai\Models\HotelAssistantSettings;
use App\Domains\Audit\Audit;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Deposits\DepositNotPossible;
use App\Domains\Deposits\DepositPresenter;
use App\Domains\Deposits\Deposits;
use App\Domains\Deposits\Jobs\TellGuestAboutDeposit;
use App\Domains\Deposits\MerchantCheck;
use App\Domains\Deposits\Models\DepositAttempt;
use App\Domains\Deposits\Models\DepositLink;
use App\Domains\Deposits\Models\MerchantAccount;
use App\Domains\Deposits\Reconciliation;
use App\Domains\Folio\RestaurantSettings;
use App\Domains\Tenancy\HotelClock;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Deposits in the dashboard (Sprint F): the hotel's payment account and
 * rules, the deposits of the month, refunds to make, the accountant's
 * export; and, on a booking, sending the link or confirming a transfer.
 */
final class DepositController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Deposits $deposits,
    ) {}

    public function index(Request $request, Reconciliation $reconciliation): Response
    {
        $hotel = $this->context->hotel();
        $month = $this->month($request);
        $settings = HotelAssistantSettings::current();
        $manages = $request->user()?->can('hotel.settings.manage') === true;
        $with = ['booking.guest', 'confirmer'];
        $bank = $settings->bank_transfer ?? [];

        return Inertia::render('hotel/deposits', [
            'month' => $month->format('Y-m'),
            'online' => $this->deposits->online(),
            'canManage' => $manages,
            'canRefund' => $request->user()?->can('folio.payments') === true,
            // 0.36: one account per provider; each can be on at the same time.
            'accounts' => $manages ? MerchantAccount::query()->get()->mapWithKeys(fn (MerchantAccount $account): array => [$account->driver => [
                'driver' => $account->driver,
                'mode' => $account->mode,
                'active' => $account->active,
                'masked' => $account->maskedCredentials(),
                'checked_at' => $account->checked_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
                'last_error' => $account->last_error,
            ]])->all() : [],
            'bank' => $manages ? [
                'enabled' => (bool) ($bank['enabled'] ?? false),
                'bank_name' => (string) ($bank['bank_name'] ?? ''),
                'account_holder' => (string) ($bank['account_holder'] ?? ''),
                'account_number' => (string) ($bank['account_number'] ?? ''),
                'iban' => (string) ($bank['iban'] ?? ''),
                'swift' => (string) ($bank['swift'] ?? ''),
                'branch' => (string) ($bank['branch'] ?? ''),
                'note' => (string) ($bank['note'] ?? ''),
                'ready' => $settings->takesBankTransfer(),
            ] : null,
            'transfers' => DepositAttempt::query()->with(['link.booking.guest'])->where('method', 'bank_transfer')->where('status', 'pending')
                ->orderByRaw('proof_at is null')->orderBy('expires_at')->limit(50)->get()
                ->map(fn (DepositAttempt $a): array => DepositPresenter::transfer($a))->values(),
            // Known before the first save, so the provider can be set up in one go.
            'notifyUrl' => $manages ? route('webhooks.deposits.hotel', $hotel->id) : null,
            'drivers' => collect(MerchantAccount::DRIVERS)
                ->filter(fn (array $d, string $key): bool => $key !== 'simulation' || (bool) config('simulation.enabled'))
                ->map(fn (array $d, string $key): array => ['value' => $key, 'label' => $d['label'], 'fields' => $d['fields']])->values(),
            'settings' => [
                'deposit_required' => $settings->deposit_required,
                'deposit_percent' => $settings->deposit_percent,
                'deposit_hours' => $settings->deposit_hours,
                'deposit_online' => $settings->deposit_online,
                'deposit_allow_full' => $settings->deposit_allow_full,
                'deposit_refund_days' => $settings->deposit_refund_days,
                'prepay_discount_percent' => $settings->prepay_discount_percent,
                'pay_at_hotel_hold_until' => $settings->pay_at_hotel_hold_until,
            ],
            'totals' => $reconciliation->month($month),
            'refunds' => DepositLink::query()->with($with)->where('refund_status', 'due')->oldest('updated_at')->get()
                ->map(fn (DepositLink $l): array => [...DepositPresenter::row($l), 'online_refund' => $this->deposits->onlineRefunder($l) !== null])->values(),
            'open' => DepositLink::query()->with($with)->where('status', 'open')->orderBy('expires_at')->limit(50)->get()->map(fn (DepositLink $l): array => DepositPresenter::row($l))->values(),
            'paid' => DepositLink::query()->with($with)->where('status', 'paid')
                ->whereBetween('paid_at', [$month->shiftTimezone($hotel->timezone)->utc(), $month->addMonth()->shiftTimezone($hotel->timezone)->utc()])
                ->latest('paid_at')->get()->map(fn (DepositLink $l): array => DepositPresenter::row($l))->values(),
        ]);
    }

    public function saveAccount(Request $request): RedirectResponse
    {
        $drivers = array_keys(array_filter(MerchantAccount::DRIVERS, fn (array $d, string $key): bool => $key !== 'simulation' || (bool) config('simulation.enabled'), ARRAY_FILTER_USE_BOTH));
        $data = $request->validate([
            'driver' => ['required', Rule::in($drivers)],
            'mode' => ['required', Rule::in(['test', 'live'])],
            'active' => ['required', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:200'],
        ]);

        // 0.36: each provider has its own account; saving one leaves the others as they are.
        $account = MerchantAccount::forDriver($data['driver']) ?? new MerchantAccount(['driver' => $data['driver']]);
        $credentials = $account->exists ? $account->credentials : [];

        foreach (MerchantAccount::DRIVERS[$data['driver']]['fields'] as $field) {
            $value = trim((string) ($data['credentials'][$field] ?? ''));
            if ($value !== '') {
                $credentials[$field] = $value;
            }
        }

        $missing = array_values(array_filter(MerchantAccount::DRIVERS[$data['driver']]['fields'], fn (string $f): bool => ($credentials[$f] ?? '') === ''));
        if ((bool) $data['active'] && $missing !== []) {
            throw ValidationException::withMessages(['credentials' => __('Fill in every key before turning payments on: :fields.', ['fields' => implode(', ', $missing)])]);
        }

        $account->fill(['driver' => $data['driver'], 'mode' => $data['mode'], 'active' => (bool) $data['active'], 'credentials' => $credentials])->save();

        Audit::record('deposits.account_saved', 'Payment account saved', subject: $account, causer: $request->user(), properties: [
            'driver' => $account->driver, 'mode' => $account->mode, 'active' => $account->active, 'keys_changed' => array_keys(array_filter((array) ($data['credentials'] ?? []))),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment account saved.')]);

        return back();
    }

    public function checkAccount(Request $request, MerchantCheck $check): RedirectResponse
    {
        $driver = (string) $request->input('driver', '');
        $account = $driver === '' ? MerchantAccount::current() : MerchantAccount::forDriver($driver);
        abort_if($account === null, 404);

        $error = $check->run($account);
        $account->forceFill(['checked_at' => now(), 'last_error' => $error])->save();

        Inertia::flash('toast', $error === null
            ? ['type' => 'success', 'message' => __('The provider accepted the keys.')]
            : ['type' => 'error', 'message' => __('The provider refused the keys: :error', ['error' => $error])]);

        return back();
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'deposit_online' => ['required', 'boolean'],
            'deposit_allow_full' => ['required', 'boolean'],
            'deposit_refund_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'prepay_discount_percent' => ['required', 'integer', 'min:0', 'max:50'],
            'pay_at_hotel_hold_until' => ['nullable', 'date_format:H:i'],
        ]);

        $settings = HotelAssistantSettings::current();
        $settings->fill($data)->save();

        Audit::record('deposits.settings_saved', 'Deposit rules saved', subject: $settings, causer: $request->user(), properties: $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deposit rules saved.')]);

        return back();
    }

    /**
     * 0.36: the hotel's bank details for transfers. Stored encrypted; shown
     * to a guest only on their own payment page.
     */
    public function saveBank(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'account_holder' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:60'],
            'iban' => ['nullable', 'string', 'max:40'],
            'swift' => ['nullable', 'string', 'max:15'],
            'branch' => ['nullable', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $bank = array_map(fn (mixed $v): mixed => is_string($v) ? trim($v) : $v, $data);
        if ($bank['enabled'] && (($bank['account_holder'] ?? '') === '' || (($bank['account_number'] ?? '').($bank['iban'] ?? '')) === '')) {
            throw ValidationException::withMessages(['account_number' => __('Enter the account holder and the account number (or IBAN) before turning bank transfer on.')]);
        }

        $settings = HotelAssistantSettings::current();
        $settings->forceFill(['bank_transfer' => $bank])->save();

        Audit::record('deposits.bank_saved', 'Bank transfer details saved', subject: $settings, causer: $request->user(), properties: ['enabled' => $bank['enabled']]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bank transfer details saved.')]);

        return back();
    }

    /** 0.36: staff saw the transfer on the hotel's bank account. */
    public function approveTransfer(Request $request, DepositAttempt $attempt): RedirectResponse
    {
        try {
            $this->deposits->approveTransfer($attempt, $this->user($request));
        } catch (DepositNotPossible $e) {
            throw ValidationException::withMessages(['transfer' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transfer approved; the guest gets the receipt.')]);

        return back();
    }

    /** 0.36: the money is not on the account: the guest is told. */
    public function rejectTransfer(Request $request, DepositAttempt $attempt): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->deposits->rejectTransfer($attempt, $this->user($request), (string) $data['reason']);
        } catch (DepositNotPossible $e) {
            throw ValidationException::withMessages(['transfer' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Transfer rejected; the guest is told.')]);

        return back();
    }

    /** 0.36: the receipt the guest uploaded, for staff only. */
    public function proof(Request $request, DepositAttempt $attempt): StreamedResponse
    {
        abort_if($attempt->proof_path === null || ! Storage::disk('local')->exists($attempt->proof_path), 404);
        Audit::record('deposits.transfer_proof_viewed', 'Bank transfer receipt viewed', subject: $attempt->link->booking, causer: $request->user(), properties: ['reference' => $attempt->reference]);

        return Storage::disk('local')->response($attempt->proof_path, $attempt->reference.'.'.pathinfo($attempt->proof_path, PATHINFO_EXTENSION), [
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The month's deposits as CSV, for the accountant.
     */
    public function export(Request $request, Reconciliation $reconciliation): StreamedResponse
    {
        $month = $this->month($request);
        $rows = $reconciliation->rows($month);
        $name = 'acomptes-'.$month->format('Y-m').'.csv';

        Audit::record('deposits.exported', 'Deposits exported', causer: $request->user(), properties: ['month' => $month->format('Y-m'), 'rows' => count($rows)]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($out, $row, ';', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function refunded(Request $request, DepositLink $link): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);

        try {
            $this->deposits->markRefunded($link, $this->user($request), (string) $data['note']);
        } catch (DepositNotPossible $e) {
            throw ValidationException::withMessages(['note' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund recorded.')]);

        return back();
    }

    /** 0.39.1: refund through the provider the guest paid with (Stripe). */
    public function refundOnline(Request $request, DepositLink $link): RedirectResponse
    {
        try {
            $this->deposits->refundOnline($link, $this->user($request));
        } catch (DepositNotPossible $e) {
            throw ValidationException::withMessages(['refund' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Refund sent. The guest is told on WhatsApp.')]);

        return back();
    }

    /** Send (or send again) the payment link to the guest on WhatsApp. */
    public function send(Request $request, Booking $booking): RedirectResponse
    {
        try {
            $link = $this->deposits->linkFor($booking, $this->user($request));
        } catch (DepositNotPossible $e) {
            throw ValidationException::withMessages(['deposit' => $e->getMessage()]);
        }

        $link->forceFill(['sent_at' => now()])->save();
        TellGuestAboutDeposit::dispatch($link->id, 'link');
        Audit::record('deposits.link_sent', 'Deposit link sent to the guest', subject: $booking, causer: $request->user(), properties: ['link' => $link->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment link sent on WhatsApp.')]);

        return back();
    }

    /** Staff saw the money arrive: confirm the deposit by hand. */
    public function confirm(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(RestaurantSettings::METHODS)],
            'amount_minor' => ['nullable', 'integer', 'min:1', 'max:1000000000'],
        ]);

        try {
            $link = $this->deposits->linkFor($booking, $this->user($request));
            $this->deposits->confirmManually($link, $this->user($request), (string) $data['method'], isset($data['amount_minor']) ? (int) $data['amount_minor'] : null);
        } catch (DepositNotPossible $e) {
            throw ValidationException::withMessages(['deposit' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deposit recorded; the guest gets the receipt.')]);

        return back();
    }

    private function month(Request $request): CarbonImmutable
    {
        $value = (string) $request->query('month', '');
        $today = HotelClock::today($this->context->hotel());

        return preg_match('/^\d{4}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('Y-m-d', $value.'-01', $today->getTimezone())?->startOfDay() ?? $today->startOfMonth()
            : $today->startOfMonth();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
