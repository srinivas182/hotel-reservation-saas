<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Lifecycle\Closures;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Super admin: close or reopen a hotel.
 */
final class PlatformLifecycleController extends Controller
{
    public function close(Request $request, Hotel $hotel, #[CurrentUser] User $user, Closures $closures): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        try {
            $closures->close($hotel, $user, 'platform', (string) $data['reason']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return back();
    }

    public function reopen(Hotel $hotel, #[CurrentUser] User $user, Closures $closures): RedirectResponse
    {
        try {
            $closures->reopen($hotel, $user);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['reopen' => $e->getMessage()]);
        }

        return back();
    }
}
