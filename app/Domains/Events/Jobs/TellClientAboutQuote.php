<?php

declare(strict_types=1);

namespace App\Domains\Events\Jobs;

use App\Domains\Audit\Audit;
use App\Domains\Conversations\Exceptions\CannotSend;
use App\Domains\Conversations\MessageSender;
use App\Domains\Conversations\MessageSource;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Events\Models\Event;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Templates\MessageTemplate;
use App\Domains\WhatsApp\Templates\TemplateStatus;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a WhatsApp client their quote link (when sent) or a thank-you (when
 * accepted). The job is encrypted on the queue because it carries the link.
 */
final class TellClientAboutQuote implements RequiresHotelContext, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /** Retry policy (S16): attempts before the job is marked failed. */
    public int $tries = 3;

    /** @var list<int> seconds between attempts */
    public array $backoff = [10, 60];

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 60;

    public function __construct(public readonly string $eventId, public readonly string $kind, public readonly ?string $link = null) {}

    public function handle(MessageSender $sender, TenantContext $context): void
    {
        $event = Event::query()->find($this->eventId);
        $conversation = $event?->conversation_id === null ? null : Conversation::query()->with('contact.guest')->find($event->conversation_id);

        if ($event === null || $conversation === null) {
            return;
        }

        $hotel = $context->hotel();
        $locale = $conversation->contact->guest->preferred_locale ?? $hotel->default_locale;
        $name = $event->client_name;

        $text = $this->kind === 'sent'
            ? (string) __('Hello :name, your quote :reference from :hotel is ready. You can read it and accept it here: :link', ['name' => $name, 'reference' => $event->reference, 'hotel' => $hotel->name, 'link' => (string) $this->link], $locale)
            : (string) __('Thank you :name, we have received your acceptance of quote :reference. Our events team will contact you about the deposit and the details.', ['name' => $name, 'reference' => $event->reference], $locale);

        try {
            if ($conversation->windowOpen()) {
                $sender->sendText($conversation, $text, null, MessageSource::System);
                $this->audit($event, 'text');

                return;
            }

            if ($this->kind === 'sent') {
                $template = MessageTemplate::query()->where('name', 'event_quote_ready')->where('status', TemplateStatus::Approved->value)
                    ->whereIn('language', [$locale, $hotel->default_locale])->orderByRaw('case when language = ? then 0 else 1 end', [$locale])->first();

                if ($template !== null) {
                    $sender->sendTemplate($conversation, $template, array_map(fn (string $v): string => MessageTemplate::cleanValue($v), [$name, $event->reference, $hotel->name, (string) $this->link]), null, MessageSource::System);
                    $this->audit($event, 'template');

                    return;
                }
            }
        } catch (CannotSend) {
            // Staff follow up instead.
        }

        $conversation->forceFill(['needs_attention' => true, 'handover_reason' => 'event_client_not_told:'.$this->kind])->save();
    }

    private function audit(Event $event, string $how): void
    {
        Audit::record('events.client_told', 'The client was told about the quote on WhatsApp', subject: $event, properties: ['kind' => $this->kind, 'how' => $how]);
    }
}
