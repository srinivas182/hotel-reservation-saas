<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Http\Controllers;

use App\Domains\Tenancy\HotelMembershipService;
use App\Domains\Tenancy\Http\Middleware\ResolveCurrentHotel;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SwitchHotelController extends Controller
{
    public function __invoke(
        Request $request,
        Hotel $hotel,
        #[CurrentUser] User $user,
        HotelMembershipService $memberships,
    ): RedirectResponse {
        abort_if($memberships->accessibleHotel($user, (string) $hotel->getKey()) === null, 403);

        $request->session()->put(ResolveCurrentHotel::SESSION_KEY, $hotel->getKey());

        return to_route('dashboard');
    }
}
