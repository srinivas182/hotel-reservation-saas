<?php

declare(strict_types=1);

namespace App\Domains\Conversations;

use App\Domains\Bookings\GuestDirectory;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\WhatsAppContact;

/**
 * Finds or creates a guest's contact and conversation (current hotel).
 * A WhatsApp contact is linked to the guest record with the same phone
 * number, so WhatsApp and bookings share one guest.
 */
final class ConversationDirectory
{
    public function __construct(private readonly GuestDirectory $guests) {}

    public function forWaId(string $waId, ?string $profileName = null): Conversation
    {
        $contact = WhatsAppContact::query()->firstOrCreate(['wa_id' => $waId], ['profile_name' => $profileName]);

        if ($profileName !== null && $profileName !== '' && $contact->profile_name !== $profileName) {
            $contact->forceFill(['profile_name' => $profileName])->save();
        }

        if ($contact->guest_id === null) {
            $phone = '+'.ltrim($waId, '+');
            $guest = $this->guests->findByPhone($phone);

            if ($guest === null) {
                $name = trim((string) ($profileName ?? ''));
                [$first, $last] = array_pad(preg_split('/\s+/', $name, 2) ?: [], 2, null);

                $guest = $this->guests->findOrCreate([
                    'first_name' => is_string($first) && $first !== '' ? $first : $phone,
                    'last_name' => is_string($last) && $last !== '' ? $last : null,
                    'phone' => $phone,
                ]);
            }

            $contact->forceFill(['guest_id' => $guest->id])->save();
        }

        return Conversation::query()->firstOrCreate(['whatsapp_contact_id' => $contact->id], ['status' => 'open']);
    }
}
