<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Identity\Enums\PlatformRole;
use App\Domains\Support\Models\SavedReply;
use App\Domains\Support\Models\SupportSettings;
use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\SupportDesk;
use App\Domains\Support\SupportDrafter;
use App\Domains\Support\SupportWhatsApp;
use App\Domains\Support\TicketView;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Platform → Support: requests, replies, notes, AI drafts, saved replies,
 * first-response targets and satisfaction.
 */
final class PlatformSupportController extends Controller
{
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $filter = (string) $request->query('filter', 'open');
        $tickets = SupportTicket::query()->with(['hotel', 'assignee'])
            ->when($filter === 'open', fn ($q) => $q->where('status', '!=', 'solved'))
            ->when($filter === 'late', fn ($q) => $q->whereNull('first_responded_at')->where('status', '!=', 'solved')->where('first_response_due_at', '<', now()))
            ->when($filter === 'mine', fn ($q) => $q->where('assignee_id', $user->id)->where('status', '!=', 'solved'))
            ->when($filter === 'solved', fn ($q) => $q->where('status', 'solved'))
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 else 2 end")->orderBy('first_response_due_at')->limit(200)->get();

        $rated = SupportTicket::query()->whereNotNull('rating')->where('solved_at', '>=', now()->subDays(90));
        $settings = SupportSettings::current();

        return Inertia::render('platform/support', [
            'filter' => $filter,
            'tickets' => $tickets->map(fn (SupportTicket $t): array => TicketView::summary($t))->values(),
            'counts' => [
                'open' => SupportTicket::query()->where('status', '!=', 'solved')->count(),
                'late' => SupportTicket::query()->whereNull('first_responded_at')->where('status', '!=', 'solved')->where('first_response_due_at', '<', now())->count(),
                'mine' => SupportTicket::query()->where('assignee_id', $user->id)->where('status', '!=', 'solved')->count(),
            ],
            'satisfaction' => ['good' => (clone $rated)->where('rating', 'good')->count(), 'bad' => (clone $rated)->where('rating', 'bad')->count()],
            'settings' => ['response_hours' => $settings->response_hours, 'ai_drafts' => $settings->ai_drafts, 'whatsapp_hotel_id' => $settings->whatsapp_hotel_id, 'whatsapp_template' => $settings->whatsapp_template, 'whatsapp_ready' => app(SupportWhatsApp::class)->available()],
            'hotels' => Hotel::query()->orderBy('name')->get(['id', 'name'])->values(),
            'savedReplies' => SavedReply::query()->orderBy('title')->get(['id', 'title', 'body'])->values(),
        ]);
    }

    public function show(SupportTicket $ticket): Response
    {
        $locale = $ticket->requester?->preferred_locale === 'en' ? 'en' : 'fr';

        return Inertia::render('platform/support-ticket', [
            'ticket' => TicketView::full($ticket, true),
            'agents' => User::query()->whereIn('platform_role', [PlatformRole::Operations->value, PlatformRole::SuperAdmin->value])->whereNull('deactivated_at')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values(),
            'savedReplies' => SavedReply::query()->orderBy('title')->get()->map(fn (SavedReply $r): array => ['id' => $r->id, 'title' => $r->title, 'text' => $r->personalised($locale, [
                'hotel' => (string) $ticket->hotel?->name, 'name' => (string) $ticket->requester?->first_name, 'agent' => (string) request()->user()?->first_name,
            ])])->values(),
            'aiDrafts' => SupportSettings::current()->ai_drafts,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, #[CurrentUser] User $user, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'internal' => ['required', 'boolean'], 'solve' => ['nullable', 'boolean'], 'ai_drafted' => ['nullable', 'boolean']]);
        $desk->replyFromSupport($ticket, $user, (string) $data['body'], (bool) $data['internal'], (bool) ($data['ai_drafted'] ?? false), (bool) ($data['solve'] ?? false) && ! (bool) $data['internal']);

        return back();
    }

    public function update(Request $request, SupportTicket $ticket, #[CurrentUser] User $user, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'assignee_id' => ['nullable', 'string', Rule::exists('users', 'id')->whereNotNull('platform_role')],
            'priority' => ['required', Rule::in(['normal', 'high', 'urgent'])],
            'status' => ['required', Rule::in(['open', 'waiting_hotel', 'solved'])],
        ]);

        if ($data['status'] === 'solved' && $ticket->status !== 'solved') {
            $desk->solve($ticket, $user);
        }
        $ticket->forceFill(['assignee_id' => $data['assignee_id'] ?? null, 'priority' => $data['priority'], 'status' => $data['status']])->save();
        Audit::record('support.updated', 'Support request updated', subject: $ticket->hotel, causer: $user, properties: ['ticket' => $ticket->id, ...$data]);

        return back();
    }

    public function draft(SupportTicket $ticket, #[CurrentUser] User $user, SupportDrafter $drafter): JsonResponse
    {
        abort_unless(SupportSettings::current()->ai_drafts, 403);

        try {
            return response()->json(['draft' => $drafter->draft($ticket, $user)]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }
    }

    public function settings(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'response_hours' => ['required', 'array'], 'response_hours.default' => ['required', 'integer', 'min:1', 'max:168'], 'response_hours.*' => ['integer', 'min:1', 'max:168'],
            'ai_drafts' => ['required', 'boolean'],
            'whatsapp_hotel_id' => ['nullable', 'string', Rule::exists('hotels', 'id')],
            'whatsapp_template' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
        ]);
        SupportSettings::current()->update($data);
        Audit::record('support.settings_saved', 'Support settings saved', causer: $user);

        return back();
    }

    public function saveReply(Request $request, #[CurrentUser] User $user, ?SavedReply $reply = null): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:100'], 'body' => ['required', 'array'], 'body.fr' => ['required', 'string', 'max:5000'], 'body.en' => ['required', 'string', 'max:5000']]);
        $reply === null ? SavedReply::query()->create($data) : $reply->update($data);

        return back();
    }

    public function storeReply(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveReply($request, $user, null);
    }

    public function updateReply(Request $request, SavedReply $reply, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveReply($request, $user, $reply);
    }

    public function destroyReply(SavedReply $reply): RedirectResponse
    {
        $reply->delete();

        return back();
    }
}
