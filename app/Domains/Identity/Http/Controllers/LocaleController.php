<?php

declare(strict_types=1);

namespace App\Domains\Identity\Http\Controllers;

use App\Domains\Hotel\Support\Locales;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Language switcher. Signed-in users keep their choice on their account;
 * visitors keep it for the session.
 */
final class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate(['locale' => ['required', Rule::in(Locales::codes())]]);

        $user = $request->user();

        if ($user instanceof User) {
            $user->forceFill(['preferred_locale' => $validated['locale']])->save();
        }

        $request->session()->put('locale', $validated['locale']);

        return back();
    }
}
