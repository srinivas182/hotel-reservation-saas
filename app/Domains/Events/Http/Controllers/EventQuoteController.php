<?php

declare(strict_types=1);

namespace App\Domains\Events\Http\Controllers;

use App\Domains\Events\Exceptions\EventNotPossible;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventQuote;
use App\Domains\Events\Models\EventSettings;
use App\Domains\Events\Quotes;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class EventQuoteController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Quotes $quotes,
    ) {}

    public function store(Request $request, Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:60'],
            'lines.*.kind' => ['required', Rule::in(['space', 'package', 'extra', 'free'])],
            'lines.*.ref' => ['nullable', 'integer', Rule::exists('event_packages', 'id')->where('hotel_id', $this->context->id())],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'discount_reason' => ['nullable', 'string', 'max:300'],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ]);

        $discount = $this->minor($data['discount'] ?? 0);

        if ($discount > 0 && ! $user->can('events.discount')) {
            abort(403);
        }

        if ($discount > 0 && trim((string) ($data['discount_reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['discount_reason' => __('Give a reason for the discount.')]);
        }

        try {
            $this->quotes->create(
                $event,
                array_values(array_map(fn (array $l): array => [
                    'kind' => (string) $l['kind'],
                    'ref' => $l['ref'] ?? null,
                    'description' => $l['description'] ?? null,
                    'quantity' => (int) $l['quantity'],
                    'unit_price' => isset($l['unit_price']) ? $this->minor($l['unit_price']) : null,
                ], $data['lines'])),
                $discount,
                $data['discount_reason'] ?? null,
                isset($data['valid_until']) ? CarbonImmutable::parse((string) $data['valid_until']) : null,
                $data['terms'] ?? null,
                $user,
            );
        } catch (EventNotPossible $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }

        return back();
    }

    public function send(EventQuote $quote, #[CurrentUser] User $user): RedirectResponse
    {
        try {
            $token = $this->quotes->send($quote, $user);
        } catch (EventNotPossible $e) {
            throw ValidationException::withMessages(['quote' => $e->getMessage()]);
        }

        // The link is shown once; only its hash is kept.
        Inertia::flash('quote_link', Quotes::link($token));

        return back();
    }

    public function accept(Request $request, EventQuote $quote, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:150'], 'title' => ['nullable', 'string', 'max:150']]);

        try {
            $this->quotes->accept($quote, (string) $data['name'], $data['title'] ?? null, null, 'staff', $user);
        } catch (EventNotPossible $e) {
            throw ValidationException::withMessages(['quote' => $e->getMessage()]);
        }

        return back();
    }

    public function settings(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $enabled = HotelSettings::current()->enabled_locales ?? [$this->context->hotel()->default_locale];
        $data = $request->validate([
            'deposit_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'option_days' => ['required', 'integer', 'min:1', 'max:90'],
            'quote_valid_days' => ['required', 'integer', 'min:1', 'max:180'],
            'terms' => ['nullable', 'array'],
            'terms.*' => ['nullable', 'string', 'max:5000'],
        ]);

        EventSettings::current()->update([
            'deposit_percent' => (int) $data['deposit_percent'],
            'option_days' => (int) $data['option_days'],
            'quote_valid_days' => (int) $data['quote_valid_days'],
            'terms' => array_filter(array_intersect_key((array) ($data['terms'] ?? []), array_flip($enabled)), fn ($t): bool => is_string($t) && trim($t) !== ''),
        ]);
        \App\Domains\Audit\Audit::record('events.settings_updated', 'Event settings updated', subject: $this->context->hotel(), causer: $user);

        return back();
    }

    private function minor(mixed $amount): int
    {
        return Money::of((string) $amount, $this->context->hotel()->currency, roundingMode: RoundingMode::HalfUp)->getMinorAmount()->toInt();
    }
}
