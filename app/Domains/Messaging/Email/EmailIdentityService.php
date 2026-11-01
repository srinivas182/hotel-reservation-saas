<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Email;

use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Messaging\Email\Mail\ReplyToVerificationMail;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Changes a hotel's sender name and reply-to address, and confirms new
 * reply-to addresses by email.
 */
final class EmailIdentityService
{
    public const VERIFICATION_HOURS = 48;

    public function __construct(private readonly TenantContext $context) {}

    public function update(Hotel $hotel, ?string $senderName, ?string $replyTo): void
    {
        $this->context->run($hotel, function () use ($hotel, $senderName, $replyTo): void {
            $settings = HotelSettings::current();
            $replyTo = $replyTo === null || trim($replyTo) === '' ? null : Str::lower(trim($replyTo));
            $replyToChanged = $replyTo !== $settings->email_reply_to;

            $settings->email_sender_name = $senderName === null || trim($senderName) === '' ? null : trim($senderName);

            if ($replyToChanged) {
                $settings->email_reply_to = $replyTo;
                $settings->email_reply_to_verified_at = null;
                $settings->email_reply_to_token_hash = null;
                $settings->email_reply_to_token_expires_at = null;
            }

            $changed = array_keys($settings->getDirty());
            $settings->save();

            if ($replyToChanged && $replyTo !== null) {
                $this->sendVerification($hotel, $settings);
            }

            if ($changed !== []) {
                Audit::record('hotel.email_identity_updated', 'Email sender settings updated', subject: $hotel, properties: [
                    'sender_name' => $settings->email_sender_name,
                    'reply_to' => $settings->email_reply_to,
                ]);
            }
        });
    }

    public function resendVerification(Hotel $hotel): void
    {
        $this->context->run($hotel, function () use ($hotel): void {
            $settings = HotelSettings::current();

            abort_if($settings->email_reply_to === null || $settings->email_reply_to_verified_at !== null, 422);

            $this->sendVerification($hotel, $settings);
        });
    }

    /**
     * Confirm a reply-to address from the emailed link. The link is opened
     * without a hotel selected, so this is an audited cross-hotel lookup.
     */
    public function verify(string $token): bool
    {
        $settings = $this->context->bypass('Reply-to confirmation link', fn (): ?HotelSettings => HotelSettings::query()
            ->where('email_reply_to_token_hash', hash('sha256', $token))
            ->first());

        if ($settings === null || $settings->email_reply_to_token_expires_at === null
            || now()->greaterThan($settings->email_reply_to_token_expires_at)) {
            return false;
        }

        $hotel = Hotel::query()->findOrFail($settings->hotel_id);

        $this->context->run($hotel, function () use ($settings, $hotel): void {
            $settings->forceFill([
                'email_reply_to_verified_at' => now(),
                'email_reply_to_token_hash' => null,
                'email_reply_to_token_expires_at' => null,
            ])->save();

            Audit::record('hotel.reply_to_verified', 'Reply-to address confirmed', subject: $hotel, properties: [
                'reply_to' => $settings->email_reply_to,
            ]);
        });

        return true;
    }

    private function sendVerification(Hotel $hotel, HotelSettings $settings): void
    {
        $token = Str::random(48);

        $settings->forceFill([
            'email_reply_to_token_hash' => hash('sha256', $token),
            'email_reply_to_token_expires_at' => now()->addHours(self::VERIFICATION_HOURS),
        ])->save();

        Mail::to((string) $settings->email_reply_to)
            ->locale($hotel->default_locale)
            ->send(new ReplyToVerificationMail($hotel->name, route('email.reply-to.verify', $token)));
    }
}
