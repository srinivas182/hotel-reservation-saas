<?php

declare(strict_types=1);

namespace App\Domains\Demo;

use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Domains\WhatsApp\Webhooks\ProcessWebhookEvent;
use App\Domains\WhatsApp\Webhooks\WebhookIntake;
use Illuminate\Support\Str;

/**
 * A: guest messages played into the demo hotel exactly as if they came
 * from WhatsApp, so the real assistant and the real screens react.
 */
final class DemoScenarios
{
    public const IN_HOUSE_PHONE = '+2250700000099';

    /**
     * @return array<string, array{label: string, text: string, from: string, name: string}>
     */
    public static function all(): array
    {
        return [
            'room' => ['label' => __('A guest asks for a room tonight'), 'text' => 'Bonsoir, avez-vous une chambre pour 2 personnes ce soir ? Quel est le prix avec petit-déjeuner ?', 'from' => 'random', 'name' => 'Moussa Koné'],
            'english' => ['label' => __('A guest writes in English'), 'text' => 'Hello, is there airport pickup? I land tomorrow at 7 pm and need a room for 3 nights.', 'from' => 'random', 'name' => 'Sarah Miller'],
            'order' => ['label' => __('A guest orders from their room'), 'text' => 'Bonjour, je suis dans la chambre. Pouvez-vous m’apporter deux cafés et un jus de bissap ?', 'from' => self::IN_HOUSE_PHONE, 'name' => 'Aïcha Traoré'],
            'event' => ['label' => __('A company asks about a seminar'), 'text' => 'Bonjour, nous organisons un séminaire de 40 personnes le mois prochain sur 2 jours. Avez-vous une salle et des chambres ?', 'from' => 'random', 'name' => 'Société Ivoire Conseil'],
        ];
    }

    public function play(Hotel $hotel, string $text, string $from, string $name): string
    {
        abort_unless($hotel->is_demo, 403);
        $waId = ltrim($from === 'random' ? '22507'.random_int(10000000, 99999999) : $from, '+');
        $phoneNumberId = (string) app(TenantContext::class)->run($hotel, fn () => WhatsAppAccount::query()->value('phone_number_id'));

        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'demo', 'changes' => [['field' => 'messages', 'value' => [
            'messaging_product' => 'whatsapp',
            'metadata' => ['phone_number_id' => $phoneNumberId, 'display_phone_number' => '22500000000'],
            'contacts' => [['wa_id' => $waId, 'profile' => ['name' => $name]]],
            'messages' => [['from' => $waId, 'id' => 'wamid.DEMO'.Str::upper(Str::random(20)), 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];

        foreach (app(WebhookIntake::class)->store($payload) as $event) {
            ProcessWebhookEvent::dispatch($event->id);
        }

        return $waId;
    }

    /**
     * The latest demo conversation, for the live panel.
     *
     * @return array{name: string|null, messages: array<int, array{direction: string, body: string, at: string}>}|null
     */
    public function feed(Hotel $hotel, ?string $waId): ?array
    {
        return app(TenantContext::class)->run($hotel, function () use ($waId): ?array {
            $contact = $waId === null ? null : WhatsAppContact::query()->where('wa_id', $waId)->first();
            $conversation = $contact === null ? null : Conversation::query()->where('whatsapp_contact_id', $contact->id)->first();

            if ($conversation === null) {
                return null;
            }

            return [
                'name' => $contact->profile_name,
                'messages' => Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->get()
                    ->map(fn (Message $m): array => ['direction' => $m->direction, 'body' => (string) ($m->body ?? ''), 'at' => $m->sent_at->format('H:i')])->values()->all(),
            ];
        });
    }
}
