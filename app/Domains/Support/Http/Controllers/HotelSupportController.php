<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Support\Models\SupportTicket;
use App\Domains\Support\SupportDesk;
use App\Domains\Support\SupportWhatsApp;
use App\Domains\Support\TicketView;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hotel side: contact support, follow requests, reply, rate.
 */
final class HotelSupportController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(#[CurrentUser] User $user): Response
    {
        return Inertia::render('hotel/support', [
            'tickets' => SupportTicket::query()->where('hotel_id', $this->context->id())->latest('id')->limit(100)->get()
                ->map(fn (SupportTicket $t): array => TicketView::summary($t))->values(),
            'categories' => collect(SupportTicket::CATEGORIES)->map(fn (string $c): array => ['value' => $c, 'label' => SupportTicket::categoryLabel($c)])->values(),
            'whatsapp' => app(SupportWhatsApp::class)->available() && $user->phone_e164 !== null,
            'version' => config('app.version'),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'body' => ['required', 'string', 'max:5000'],
            'context' => ['nullable', 'array'],
            'context.page' => ['nullable', 'string', 'max:300'],
            'context.screen' => ['nullable', 'string', 'max:100'],
            'context.browser' => ['nullable', 'string', 'max:300'],
            'context.screen_size' => ['nullable', 'string', 'max:30'],
            'context.error_ref' => ['nullable', 'string', 'max:64'],
            'files' => ['nullable', 'array', 'max:3'],
            'files.*' => ['file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'notify_whatsapp' => ['nullable', 'boolean'],
        ]);

        $context = [
            ...array_map(fn ($v) => is_string($v) ? mb_substr($v, 0, 300) : $v, (array) ($data['context'] ?? [])),
            'version' => config('app.version'),
            'role' => $user->roles()->pluck('name')->implode(', '),
            'locale' => app()->getLocale(),
        ];

        $ticket = $desk->open($this->context->hotel(), $user, (string) $data['subject'], (string) $data['category'], (string) $data['body'], $context, $this->files($request), (bool) ($data['notify_whatsapp'] ?? false));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request sent. We will answer here and by email.')]);

        return to_route('hotel.requests.show', $ticket);
    }

    public function show(int $ticket): Response
    {
        $found = $this->find($ticket);

        return Inertia::render('hotel/support-ticket', ['ticket' => TicketView::full($found, false)]);
    }

    public function reply(Request $request, int $ticket, #[CurrentUser] User $user, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:5000'], 'files' => ['nullable', 'array', 'max:3'], 'files.*' => ['file', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);
        $desk->replyFromHotel($this->find($ticket), $user, (string) $data['body'], $this->files($request));

        return back();
    }

    public function rate(Request $request, int $ticket, #[CurrentUser] User $user, SupportDesk $desk): RedirectResponse
    {
        $data = $request->validate(['rating' => ['required', Rule::in(['good', 'bad'])], 'comment' => ['nullable', 'string', 'max:500']]);
        $found = $this->find($ticket);
        abort_unless($found->status === 'solved', 422);
        $desk->rate($found, $user, (string) $data['rating'], $data['comment'] ?? null);

        return back();
    }

    private function find(int $ticket): SupportTicket
    {
        return SupportTicket::query()->with(['messages.author', 'requester', 'assignee', 'hotel'])->where('hotel_id', $this->context->id())->findOrFail($ticket);
    }

    /**
     * @return list<UploadedFile>
     */
    private function files(Request $request): array
    {
        $files = $request->file('files');

        return is_array($files) ? array_values($files) : [];
    }
}
