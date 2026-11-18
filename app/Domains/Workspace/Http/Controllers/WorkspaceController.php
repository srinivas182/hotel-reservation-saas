<?php

declare(strict_types=1);

namespace App\Domains\Workspace\Http\Controllers;

use App\Domains\Access\Models\Role;
use App\Domains\Audit\Audit;
use App\Domains\Workspace\Models\StaffPreference;
use App\Domains\Workspace\Tours;
use App\Domains\Workspace\Workspace;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Each person's dashboard choices (Sprint E): simple or full, larger
 * text, tours; and the owner's preview of another role.
 */
final class WorkspaceController extends Controller
{
    public function mode(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::in(['simple', 'full', 'default'])]]);
        $preference = StaffPreference::for($user);
        $preference->forceFill(['ui_mode' => $data['mode'] === 'default' ? null : $data['mode']])->save();

        return back();
    }

    public function largeText(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['large_text' => ['required', 'boolean']]);
        StaffPreference::for($user)->forceFill(['large_text' => (bool) $data['large_text']])->save();

        return back();
    }

    public function tourDone(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', Rule::in(Tours::KEYS)]]);
        $preference = StaffPreference::for($user);
        $done = $preference->tours_done ?? [];

        // Finishing the welcome tour covers every feature tour released before today.
        $keys = $data['key'] === 'welcome' ? Tours::KEYS : [$data['key']];
        $preference->forceFill(['tours_done' => array_values(array_unique([...$done, ...$keys]))])->save();

        return back();
    }

    public function tourReplay(#[CurrentUser] User $user): RedirectResponse
    {
        $preference = StaffPreference::for($user);
        $preference->forceFill(['tours_done' => array_values(array_diff($preference->tours_done ?? [], ['welcome']))])->save();

        return back();
    }

    public function viewAs(Request $request, Workspace $workspace, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['role' => ['required', 'integer']]);
        $role = Role::query()->whereKey((int) $data['role'])->where(fn ($q) => $q->whereNull('hotel_id')->orWhere('hotel_id', app(\App\Domains\Tenancy\TenantContext::class)->id()))->firstOrFail();

        $workspace->startViewAs($role);
        Audit::record('workspace.view_as', 'Dashboard previewed as another role', causer: $user, properties: ['role' => $role->displayName()]);

        return redirect()->route('hotel.today');
    }

    public function stopViewAs(Workspace $workspace): RedirectResponse
    {
        $workspace->stopViewAs();

        return back();
    }
}
