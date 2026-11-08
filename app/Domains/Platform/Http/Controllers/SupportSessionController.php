<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Platform\PlatformAbility;
use App\Domains\Platform\Support\SupportAccess;
use App\Domains\Platform\Support\SupportSession;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
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

/**
 * Starting, extending and ending support sessions (platform side), and the
 * hotel's view of them (owner side: list, approval, setting).
 */
final class SupportSessionController extends Controller
{
    public function __construct(private readonly SupportAccess $support) {}

    public function start(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300'], 'mode' => ['required', Rule::in(['read', 'write'])]]);
        abort_unless($hotel->status->allowsAccess(), 403, 'This hotel is not active.');

        $session = $this->support->start($hotel, $user, trim((string) $data['reason']), (string) $data['mode']);

        if ($session->status === 'pending') {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('The owner has been asked to approve the session.')]);

            return back();
        }

        $request->session()->put(SupportAccess::SESSION_KEY, $session->id);

        return to_route('hotel.today');
    }

    /**
     * Enter an approved session.
     */
    public function enter(Request $request, SupportSession $session, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($session->user_id === $user->id && $session->isLive(), 403);
        $request->session()->put(SupportAccess::SESSION_KEY, $session->id);

        return to_route('hotel.today');
    }

    public function extend(#[CurrentUser] User $user): RedirectResponse
    {
        $session = $this->support->current() ?? abort(404);

        try {
            $this->support->extend($session, $user);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['support' => $e->getMessage()]);
        }

        return back();
    }

    public function end(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $id = $request->session()->pull(SupportAccess::SESSION_KEY);
        $session = is_string($id) ? SupportSession::query()->where('user_id', $user->id)->find($id) : null;

        if ($session !== null) {
            $this->support->end($session, $user);
        }

        return $user->platformCan(PlatformAbility::HotelsView) && $session !== null ? to_route('platform.hotels.show', $session->hotel_id) : to_route('dashboard');
    }

    /**
     * Hotel side: past and current sessions, pending approvals, the setting.
     */
    public function index(TenantContext $context): Response
    {
        return Inertia::render('hotel/support-sessions', [
            'setting' => HotelSettings::current()->support_access,
            'sessions' => SupportSession::query()->with('user')->where('hotel_id', $context->id())->latest('created_at')->limit(50)->get()->map(fn (SupportSession $s): array => [
                'id' => $s->id, 'by' => $s->user?->name, 'reason' => $s->reason, 'mode' => $s->mode, 'status' => $s->isLive() ? 'active' : ($s->status === 'active' ? 'ended' : $s->status),
                'requested' => $s->created_at?->toDateTimeString(), 'started' => $s->started_at?->toDateTimeString(), 'ended' => ($s->ended_at ?? ($s->status === 'active' ? $s->expires_at : null))?->toDateTimeString(),
                'actions' => AuditLog::query()->where('hotel_id', $context->id())->where('properties->support_session', $s->id)->count(),
            ])->values(),
        ]);
    }

    public function decide(Request $request, string $session, #[CurrentUser] User $user, TenantContext $context): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        $found = SupportSession::query()->where('hotel_id', $context->id())->findOrFail($session);

        try {
            $this->support->approve($found, $user, (bool) $data['approve']);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['support' => __('This request has already been answered.')]);
        }

        return back();
    }

    public function stop(string $session, #[CurrentUser] User $user, TenantContext $context): RedirectResponse
    {
        $found = SupportSession::query()->where('hotel_id', $context->id())->findOrFail($session);
        $this->support->end($found, $user);

        return back();
    }

    public function setting(Request $request, TenantContext $context): RedirectResponse
    {
        $data = $request->validate(['support_access' => ['required', Rule::in(['notify', 'ask_first'])]]);
        HotelSettings::current()->update(['support_access' => $data['support_access']]);
        \App\Domains\Audit\Audit::record('support.setting_changed', 'Support access setting changed', subject: $context->hotel(), causer: $request->user(), properties: $data);

        return back();
    }
}
