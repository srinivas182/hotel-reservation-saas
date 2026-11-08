<?php

declare(strict_types=1);

namespace App\Domains\Privacy;

use App\Domains\Audit\Audit;
use App\Domains\Bookings\Models\Booking;
use App\Domains\Bookings\Models\Guest;
use App\Domains\Conversations\Models\Conversation;
use App\Domains\Conversations\Models\Message;
use App\Domains\Conversations\Models\WhatsAppContact;
use App\Domains\Orders\Models\StayPhone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A guest's request to see or delete their data (ARTCI and similar laws).
 * Anonymising keeps bookings and amounts for the hotel's accounts.
 */
final class GuestData
{
    /**
     * @return array<string, mixed>
     */
    public function export(Guest $guest, User $by): array
    {
        $contacts = WhatsAppContact::query()->where('guest_id', $guest->id)->get();
        $conversations = Conversation::query()->whereIn('whatsapp_contact_id', $contacts->pluck('id'))->get();

        $data = [
            'guest' => $guest->only(['first_name', 'last_name', 'phone_e164', 'email', 'preferred_locale', 'notes', 'whatsapp_opt_in', 'marketing_opt_in', 'created_at']),
            'bookings' => Booking::query()->where('guest_id', $guest->id)->get()->map(fn (Booking $b): array => $b->only(['reference', 'check_in', 'check_out', 'status', 'adults', 'children', 'total_minor', 'currency', 'created_at']))->values()->all(),
            'whatsapp' => $contacts->map(fn (WhatsAppContact $c): array => ['number' => '+'.$c->wa_id, 'profile_name' => $c->profile_name])->values()->all(),
            'messages' => Message::query()->whereIn('conversation_id', $conversations->pluck('id'))->orderBy('sent_at')->get()
                ->map(fn (Message $m): array => ['at' => $m->sent_at->toIso8601String(), 'direction' => $m->direction, 'type' => $m->type, 'text' => $m->body ?? $m->transcript])->values()->all(),
            'exported_at' => now()->toIso8601String(),
        ];

        Audit::record('privacy.guest_exported', 'Guest data exported', subject: $guest, causer: $by);

        return $data;
    }

    public function anonymise(Guest $guest, User $by): void
    {
        DB::transaction(function () use ($guest): void {
            $contacts = WhatsAppContact::query()->where('guest_id', $guest->id)->get();
            $conversations = Conversation::query()->whereIn('whatsapp_contact_id', $contacts->pluck('id'))->pluck('id');

            foreach (Message::query()->whereIn('conversation_id', $conversations)->whereNotNull('media')->get() as $message) {
                $path = $message->media['path'] ?? null;
                if (is_string($path)) {
                    Storage::disk('local')->delete($path);
                }
            }

            // Through the model, so encrypted columns stay encrypted.
            Message::query()->whereIn('conversation_id', $conversations)->each(function (Message $message): void {
                $message->forceFill(['body' => '[removed]', 'media' => null, 'translation' => null, 'transcript' => null])->save();
            });

            foreach ($contacts as $contact) {
                $contact->forceFill(['wa_id' => 'removed-'.Str::lower(Str::random(12)), 'profile_name' => null])->save();
            }

            $bookings = Booking::query()->where('guest_id', $guest->id)->pluck('id');
            StayPhone::query()->whereIn('booking_id', $bookings)->delete();

            $guest->forceFill([
                'first_name' => (string) __('Anonymised guest'), 'last_name' => null, 'phone_e164' => null, 'email' => null,
                'notes' => null, 'whatsapp_opt_in' => false, 'marketing_opt_in' => false,
            ])->save();
        });

        Audit::record('privacy.guest_anonymised', 'Guest anonymised', subject: $guest, causer: $by);
    }
}
