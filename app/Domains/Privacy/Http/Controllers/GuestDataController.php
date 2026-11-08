<?php

declare(strict_types=1);

namespace App\Domains\Privacy\Http\Controllers;

use App\Domains\Bookings\Models\Guest;
use App\Domains\Privacy\GuestData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guest data requests: find a guest, export their data, or anonymise them.
 */
final class GuestDataController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D+/', '', $q) ?? '';

        $guests = $q === '' ? collect() : Guest::query()
            ->where(function ($w) use ($q, $digits): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('email', 'like', $like);
                if (strlen($digits) >= 6) {
                    $w->orWhere('phone_e164', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('first_name')->limit(30)->get();

        return Inertia::render('hotel/privacy', [
            'q' => $q,
            'guests' => $guests->map(fn (Guest $g): array => ['id' => $g->id, 'name' => trim($g->first_name.' '.($g->last_name ?? '')), 'phone' => $g->phone_e164, 'email' => $g->email])->values(),
        ]);
    }

    public function export(Guest $guest, GuestData $data, #[CurrentUser] User $user): JsonResponse
    {
        return response()->json($data->export($guest, $user), 200, ['Content-Disposition' => 'attachment; filename="guest-data.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    public function anonymise(Request $request, Guest $guest, GuestData $data, #[CurrentUser] User $user): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'in:ANONYMISE,ANONYMISER']]);
        $data->anonymise($guest, $user);

        return back();
    }
}
