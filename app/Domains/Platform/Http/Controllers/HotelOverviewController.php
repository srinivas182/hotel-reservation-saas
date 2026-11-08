<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Platform\HotelHealth;
use App\Domains\Platform\Models\HotelNote;
use App\Domains\Platform\PlatformAbility;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything about one hotel for the platform team.
 */
final class HotelOverviewController extends Controller
{
    public function show(Hotel $hotel, HotelHealth $health, #[CurrentUser] User $user): Response
    {
        $hotel->load('owner');
        $state = $health->for($hotel);

        return Inertia::render('platform/hotels/show', [
            'hotel' => [
                'id' => $hotel->id, 'name' => $hotel->name, 'country' => $hotel->country_code, 'currency' => $hotel->currency,
                'timezone' => $hotel->timezone, 'signup_source' => $hotel->signup_source, 'created_at' => $hotel->created_at?->toDateString(),
                'owner' => $hotel->owner === null ? null : ['name' => $hotel->owner->name, 'email' => $hotel->owner->email, 'phone' => $hotel->owner->phone_e164],
            ],
            'health' => $state,
            'issueLabels' => array_map(fn (string $i): string => HotelHealth::issueLabel($i), $state['issues']),
            'staff' => $hotel->members()->get()->map(fn (User $m): array => ['name' => $m->name, 'email' => $m->email, 'status' => $m->pivot->status ?? null, 'last_login' => $m->last_login_at?->toDateTimeString()])->values(),
            'history' => AuditLog::query()->where('hotel_id', $hotel->id)->latest('id')->limit(50)->get()
                ->map(fn (AuditLog $a): array => ['id' => $a->id, 'event' => $a->event, 'description' => __($a->description), 'by' => $a->causer instanceof User ? $a->causer->name : null, 'at' => $a->created_at?->toDateTimeString()])->values(),
            'notes' => HotelNote::query()->with('author')->where('hotel_id', $hotel->id)->latest('id')->get()
                ->map(fn (HotelNote $n): array => ['id' => $n->id, 'body' => $n->body, 'by' => $n->author?->name, 'at' => $n->created_at?->toDateTimeString(), 'mine' => $n->author_id === $user->id])->values(),
            'supportSessions' => \App\Domains\Platform\Support\SupportSession::query()->with('user')->where('hotel_id', $hotel->id)->latest('created_at')->limit(10)->get()
                ->map(fn (\App\Domains\Platform\Support\SupportSession $s): array => ['id' => $s->id, 'by' => $s->user?->name, 'mine' => $s->user_id === $user->id, 'reason' => $s->reason, 'mode' => $s->mode, 'status' => $s->status, 'live' => $s->isLive(), 'at' => $s->created_at?->toDateTimeString()])->values(),
            'can' => [
                'support' => $user->platformCan(PlatformAbility::Support),
                'manage' => $user->platformCan(PlatformAbility::HotelsManage),
                'billing' => $user->platformCan(PlatformAbility::BillingView),
                'audit' => $user->platformCan(PlatformAbility::AuditView),
            ],
        ]);
    }

    public function storeNote(Request $request, Hotel $hotel, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        HotelNote::query()->create(['hotel_id' => $hotel->id, 'body' => trim((string) $data['body']), 'author_id' => $user->id]);
        Audit::record('platform.note_added', 'Platform note added', subject: $hotel, causer: $user);

        return back();
    }

    public function destroyNote(Hotel $hotel, HotelNote $note, #[CurrentUser] User $user): RedirectResponse
    {
        abort_unless($note->hotel_id === $hotel->id && ($note->author_id === $user->id || $user->isSuperAdmin()), 403);
        $note->delete();
        Audit::record('platform.note_deleted', 'Platform note deleted', subject: $hotel, causer: $user);

        return back();
    }
}
