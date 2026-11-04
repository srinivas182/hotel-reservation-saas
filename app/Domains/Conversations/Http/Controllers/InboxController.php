<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Http\Controllers;

use App\Domains\Bookings\BookingStatus;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Conversations\ConversationHandover;
use App\Domains\Conversations\ConversationMode;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The staff inbox: WhatsApp conversations with guests.
 */
final class InboxController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        return $this->render($request, null);
    }

    public function show(Request $request, Conversation $conversation): Response
    {
        return $this->render($request, $conversation);
    }

    private function render(Request $request, ?Conversation $conversation): Response
    {
        $hotel = $this->context->hotel();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', 'in:unread,closing,attention'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $digits = preg_replace('/\D/', '', $search) ?? '';

        $conversations = Conversation::query()
            ->with('contact.guest')
            ->when($search !== '', fn ($query) => $query->whereHas('contact', fn ($contact) => $contact
                ->where('profile_name', 'like', "%{$search}%")
                ->when(strlen($digits) >= 3, fn ($q) => $q->orWhere('wa_id', 'like', "%{$digits}%"))
                ->orWhereHas('guest', fn ($guest) => $guest->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"))))
            ->when(($filters['filter'] ?? null) === 'unread', fn ($query) => $query->where('unread_count', '>', 0))
            ->when(($filters['filter'] ?? null) === 'attention', fn ($query) => $query->where('needs_attention', true))
            ->when(($filters['filter'] ?? null) === 'closing', fn ($query) => $query
                ->where('last_inbound_at', '>', now()->subHours(Conversation::WINDOW_HOURS))
                ->where('last_inbound_at', '<=', now()->subHours(Conversation::WINDOW_HOURS - 2)))
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        $latest = Message::query()
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->orderByDesc('sent_at')
            ->get(['id', 'conversation_id', 'type', 'body', 'direction', 'sent_at'])
            ->unique('conversation_id')
            ->keyBy('conversation_id');

        if ($conversation !== null && $conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        $account = WhatsAppAccount::query()->first();

        return Inertia::render('hotel/inbox', [
            'filters' => $filters,
            'connected' => $account !== null && $account->status->canSend(),
            'conversations' => $conversations->map(function (Conversation $item) use ($latest, $hotel): array {
                /** @var Message|null $last */
                $last = $latest->get($item->id);

                return [
                    'id' => $item->id,
                    'name' => $this->name($item),
                    'phone' => '+'.$item->contact?->wa_id,
                    'unread' => $item->unread_count,
                    'mode' => $item->mode->value,
                    'needs_attention' => $item->needs_attention,
                    'window_open' => $item->windowOpen(),
                    'last_at' => $item->last_message_at?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
                    'preview' => $last === null ? null : ($last->direction === 'out' ? '↩ ' : '').$this->preview($last),
                ];
            })->values(),
            'conversation' => $conversation === null ? null : $this->thread($conversation),
            'canReply' => $request->user()?->can('conversations.reply') === true,
            'templates' => $conversation === null ? [] : MessageTemplate::query()
                ->where('status', TemplateStatus::Approved->value)
                ->orderBy('name')
                ->get()
                ->map(fn (MessageTemplate $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'language' => $template->language,
                    'body' => $template->body,
                    'placeholders' => $template->placeholders(),
                    'examples' => $template->examples,
                ])->values(),
        ]);
    }

    public function reply(Request $request, Conversation $conversation, MessageSender $sender, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.MessageSender::MAX_TEXT]]);

        try {
            $sender->sendText($conversation, (string) $data['body'], $user);
        } catch (CannotSend $exception) {
            throw ValidationException::withMessages(['body' => $exception->getMessage()]);
        }

        return back();
    }

    public function sendTemplate(Request $request, Conversation $conversation, MessageSender $sender, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['required', 'string'],
            'values' => ['array', 'max:10'],
            'values.*' => ['nullable', 'string', 'max:500'],
        ]);

        $template = MessageTemplate::query()->whereKey((string) $data['template_id'])->first();

        if ($template === null) {
            throw ValidationException::withMessages(['template_id' => __('Choose a template.')]);
        }

        try {
            $sender->sendTemplate($conversation, $template, array_values(array_map('strval', (array) ($data['values'] ?? []))), $user);
        } catch (CannotSend $exception) {
            throw ValidationException::withMessages(['template_id' => $exception->getMessage()]);
        }

        return back();
    }

    public function mode(Request $request, Conversation $conversation, ConversationHandover $handover, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', Rule::enum(ConversationMode::class)]]);

        match (ConversationMode::from((string) $data['mode'])) {
            ConversationMode::Staff => $handover->toStaff($conversation, 'taken_over_by_staff', $user),
            ConversationMode::Assistant => $handover->toAssistant($conversation, $user),
            ConversationMode::Paused => $handover->pause($conversation, $user),
        };

        return back();
    }

    public function media(Message $message): StreamedResponse
    {
        $path = $message->media['path'] ?? null;

        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => (string) ($message->media['mime_type'] ?? 'application/octet-stream'),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function thread(Conversation $conversation): array
    {
        $hotel = $this->context->hotel();
        $conversation->load('contact.guest');
        $guest = $conversation->contact?->guest;

        $messages = $conversation->messages()
            ->with('sender')
            ->orderByDesc('sent_at')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();

        return [
            'id' => $conversation->id,
            'name' => $this->name($conversation),
            'phone' => '+'.$conversation->contact?->wa_id,
            'mode' => $conversation->mode->value,
            'mode_label' => $conversation->mode->label(),
            'needs_attention' => $conversation->needs_attention,
            'handover_reason' => $conversation->handover_reason,
            'window_open' => $conversation->windowOpen(),
            'window_closes_at' => $conversation->windowClosesAt()?->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            'bookings' => $guest === null ? [] : Booking::query()
                ->where('guest_id', $guest->id)
                ->whereNotIn('status', [BookingStatus::Cancelled->value])
                ->orderByDesc('check_in')
                ->limit(5)
                ->get()
                ->map(fn (Booking $booking): array => [
                    'id' => $booking->id,
                    'reference' => $booking->reference,
                    'status_label' => $booking->status->label(),
                    'check_in' => $booking->check_in->toDateString(),
                    'check_out' => $booking->check_out->toDateString(),
                ])->values(),
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'direction' => $message->direction,
                'type' => $message->type,
                'body' => $message->body,
                'template_name' => $message->template_name,
                'media_url' => isset($message->media['path']) ? route('hotel.inbox.media', $message) : null,
                'media_error' => isset($message->media['media_id']) && ! isset($message->media['path']) ? ($message->media['error'] ?? __('Downloading…')) : null,
                'mime_type' => $message->media['mime_type'] ?? null,
                'filename' => $message->media['filename'] ?? null,
                'location' => isset($message->media['latitude']) ? ['lat' => $message->media['latitude'], 'lng' => $message->media['longitude'] ?? 0] : null,
                'status' => $message->status->value,
                'error' => $message->error_text,
                'transcript' => $message->transcript,
                'transcript_error' => $message->transcript_error,
                'source' => $message->source->value,
                'by' => $message->sender?->name,
                'at' => $message->sent_at->setTimezone($hotel->timezone)->format('Y-m-d H:i'),
            ])->values(),
        ];
    }

    private function name(Conversation $conversation): string
    {
        $guest = $conversation->contact?->guest;

        return $guest?->name() ?: ($conversation->contact?->profile_name ?: '+'.$conversation->contact?->wa_id);
    }

    private function preview(Message $message): string
    {
        return match ($message->type) {
            'text', 'interactive', 'button' => mb_strimwidth((string) $message->body, 0, 80, '…'),
            'audio' => __('Voice note'),
            'image' => __('Photo'),
            'document' => __('Document'),
            'location' => __('Location'),
            'template' => __('Template'),
            default => __('Message'),
        };
    }
}
