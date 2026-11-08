<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Onboarding\Signups;
use App\Domains\Platform\Lifecycle\Closures;
use App\Domains\Platform\Lifecycle\HotelClosure;
use App\Domains\Tenancy\Http\Middleware\ResolveCurrentHotel;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The owner's hotels: add another, close one, download its data, reopen it.
 */
final class AccountHotelsController extends Controller
{
    public function index(#[CurrentUser] User $user): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('account/hotels', [
            'hotels' => Hotel::query()->where('owner_user_id', $user->id)->orderBy('name')->get()->map(function (Hotel $h): array {
                $closure = HotelClosure::openFor($h->id);

                return [
                    'id' => $h->id, 'name' => $h->name, 'country' => $h->country_code, 'status' => $h->status->value,
                    'closure' => $closure === null ? null : ['closed_at' => $closure->closed_at->toDateString(), 'delete_after' => $closure->delete_after->toDateString(), 'export' => $closure->export_path !== null],
                ];
            })->values(),
            'countries' => CountryTemplate::query()->where('active', true)->get()->map(fn (CountryTemplate $c): array => ['code' => $c->country_code, 'name' => $c->localizedName($locale)])->sortBy('name')->values(),
            'hasTwoFactor' => $user->hasEnabledTwoFactorAuthentication(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, Signups $signups): RedirectResponse
    {
        abort_unless($user->hasVerifiedEmail(), 403);
        $data = $request->validate([
            'hotel_name' => ['required', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', Rule::exists('country_templates', 'country_code')->where('active', true)],
            'city' => ['required', 'string', 'max:120'],
            'rooms' => ['required', 'integer', 'min:1', 'max:2000'],
        ]);

        $hotel = $signups->createHotel($user, [
            'hotel_name' => (string) $data['hotel_name'], 'country_code' => (string) $data['country_code'], 'city' => (string) $data['city'],
            'phone' => (string) ($user->phone_e164 ?? ''), 'rooms' => (int) $data['rooms'],
        ], 'self');
        $request->session()->put(ResolveCurrentHotel::SESSION_KEY, $hotel->id);

        return to_route('hotel.setup');
    }

    public function close(Request $request, Hotel $hotel, #[CurrentUser] User $user, Closures $closures): RedirectResponse
    {
        abort_unless($hotel->owner_user_id === $user->id, 403);
        $data = $request->validate(['password' => ['required', 'current_password'], 'reason' => ['nullable', 'string', 'max:500'], 'confirm' => ['required', 'in:'.$hotel->name]]);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages(['password' => __('Turn on two-step login before closing a hotel.')]);
        }

        try {
            $closures->close($hotel, $user, 'owner', $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['confirm' => $e->getMessage()]);
        }
        $request->session()->forget(ResolveCurrentHotel::SESSION_KEY);

        return to_route('account.hotels');
    }

    public function reopen(Hotel $hotel, #[CurrentUser] User $user, Closures $closures): RedirectResponse
    {
        abort_unless($hotel->owner_user_id === $user->id, 403);

        try {
            $closures->reopen($hotel, $user);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['reopen' => $e->getMessage()]);
        }

        return to_route('account.hotels');
    }

    public function export(Hotel $hotel, #[CurrentUser] User $user): BinaryFileResponse
    {
        abort_unless($hotel->owner_user_id === $user->id || $user->isSuperAdmin(), 403);
        $closure = HotelClosure::openFor($hotel->id);
        abort_if($closure?->export_path === null || ! is_file($closure->export_path), 404);

        return response()->download($closure->export_path, 'hotel-data-'.now()->format('Y-m-d').'.zip');
    }
}
