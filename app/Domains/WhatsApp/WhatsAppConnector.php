<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp;

use App\Domains\Audit\Audit;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Domains\WhatsApp\Exceptions\WhatsAppConnectionFailed;
use App\Domains\WhatsApp\Graph\GraphClient;
use App\Domains\WhatsApp\Graph\GraphException;
use App\Domains\WhatsApp\Models\WhatsAppAccount;
use App\Models\User;
use App\Support\Casts\RecoverableEncrypted;
use Illuminate\Support\Str;

/**
 * Connects a hotel's number after Embedded Signup, checks its token and
 * disconnects it.
 */
final class WhatsAppConnector
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly GraphClient $graph,
    ) {}

    public function connect(Hotel $hotel, string $code, string $wabaId, string $phoneNumberId, bool $coexistence, ?User $by = null): WhatsAppAccount
    {
        $this->ensureNotElsewhere($hotel, $phoneNumberId);

        try {
            $token = $this->graph->exchangeCode($code);
            $pin = null;

            // Numbers shared with the WhatsApp Business app stay registered there.
            if (! $coexistence) {
                $pin = (string) random_int(100000, 999999);
                $this->graph->registerPhone($phoneNumberId, $token, $pin);
            }

            $this->graph->subscribeApp($wabaId, $token);
            $details = $this->graph->phoneNumber($phoneNumberId, $token);
        } catch (GraphException $exception) {
            Audit::record('whatsapp.connect_failed', 'WhatsApp connection failed', subject: $hotel, causer: $by, properties: [
                'phone_number_id' => $phoneNumberId,
                'error' => Str::limit($exception->getMessage(), 300),
            ]);

            throw new WhatsAppConnectionFailed(__('Meta refused the connection: :reason', ['reason' => $exception->getMessage()]), previous: $exception);
        }

        $account = $this->store($hotel, $wabaId, $phoneNumberId, $coexistence, $token, $pin, $details, $by);

        Audit::record('whatsapp.connected', 'WhatsApp number connected', subject: $hotel, causer: $by, properties: [
            'display_phone' => $account->display_phone,
            'coexistence' => $coexistence,
        ]);

        return $account;
    }

    /**
     * Connect a number with a token from a Meta system user instead of
     * Embedded Signup: Meta's test number, or a number in a WhatsApp
     * Business account the platform's own business owns. The number must
     * already be registered for the Cloud API (Meta's test number is).
     */
    public function connectWithToken(Hotel $hotel, string $wabaId, string $phoneNumberId, string $token, User $by): WhatsAppAccount
    {
        $this->ensureNotElsewhere($hotel, $phoneNumberId);

        try {
            $details = $this->graph->phoneNumber($phoneNumberId, $token);
            $this->graph->subscribeApp($wabaId, $token);
        } catch (GraphException $exception) {
            Audit::record('whatsapp.connect_failed', 'WhatsApp connection failed', subject: $hotel, causer: $by, properties: [
                'phone_number_id' => $phoneNumberId,
                'manual' => true,
                'error' => Str::limit($exception->getMessage(), 300),
            ]);

            throw new WhatsAppConnectionFailed(__('Meta refused the connection: :reason', ['reason' => $exception->getMessage()]), previous: $exception);
        }

        $account = $this->store($hotel, $wabaId, $phoneNumberId, false, $token, null, $details, $by);

        Audit::record('whatsapp.connected_manually', 'WhatsApp number connected manually by the platform', subject: $hotel, causer: $by, properties: [
            'display_phone' => $account->display_phone,
            'phone_number_id' => $phoneNumberId,
        ]);

        return $account;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function store(Hotel $hotel, string $wabaId, string $phoneNumberId, bool $coexistence, string $token, ?string $pin, array $details, ?User $by): WhatsAppAccount
    {
        return $this->context->run($hotel, function () use ($wabaId, $phoneNumberId, $coexistence, $token, $pin, $details, $by): WhatsAppAccount {
            $account = WhatsAppAccount::query()->firstOrNew();
            $account->fill([
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
                'coexistence' => $coexistence,
                'status' => AccountStatus::Connected,
                'token' => $token,
                'pin' => $pin ?? $account->pin,
                'token_checked_at' => now(),
                'last_error' => null,
                'connected_by' => $by?->getKey(),
                'connected_at' => now(),
                ...$this->detailsAttributes($details),
            ])->save();

            return $account;
        });
    }

    /** A number can belong to one hotel only (checked across hotels). */
    private function ensureNotElsewhere(Hotel $hotel, string $phoneNumberId): void
    {
        $elsewhere = $this->context->bypass('WhatsApp number uniqueness check', fn (): bool => WhatsAppAccount::query()
            ->where('phone_number_id', $phoneNumberId)
            ->where('hotel_id', '!=', $hotel->getKey())
            ->exists());

        if ($elsewhere) {
            throw new WhatsAppConnectionFailed(__('This WhatsApp number is already connected to another hotel.'));
        }
    }

    /**
     * Check that the stored token still works and refresh the number's
     * details. A revoked or expired token marks the account for reconnection.
     */
    public function check(WhatsAppAccount $account): bool
    {
        if ($account->token === null) {
            if (RecoverableEncrypted::unreadable($account, 'token')) {
                $this->markReconnectRequired($account, __('The saved access key cannot be read (the server\'s APP_KEY changed). Enter the access key again.'));
            }

            return false;
        }

        try {
            $details = $this->graph->phoneNumber($account->phone_number_id, $account->token);
        } catch (GraphException $exception) {
            if ($exception->isInvalidToken()) {
                $this->markReconnectRequired($account, $exception->getMessage());
            } else {
                $account->forceFill(['last_error' => Str::limit($exception->getMessage(), 490)])->save();
            }

            return false;
        }

        $account->forceFill([
            ...$this->detailsAttributes($details),
            'token_checked_at' => now(),
            'last_error' => null,
            'status' => $account->status === AccountStatus::ReconnectRequired ? AccountStatus::Connected : $account->status,
        ])->save();

        return true;
    }

    public function markReconnectRequired(WhatsAppAccount $account, string $reason): void
    {
        if ($account->status === AccountStatus::ReconnectRequired) {
            return;
        }

        $account->forceFill([
            'status' => AccountStatus::ReconnectRequired,
            'last_error' => Str::limit($reason, 490),
        ])->save();

        Audit::record('whatsapp.reconnect_required', 'WhatsApp needs to be reconnected', subject: $account, properties: ['reason' => Str::limit($reason, 300)]);
    }

    /**
     * Stop using the number. The number itself is left with Meta (and with
     * the WhatsApp Business app for shared numbers); only our access ends.
     */
    public function disconnect(Hotel $hotel, ?User $by = null): void
    {
        $this->context->run($hotel, function () use ($hotel, $by): void {
            $account = WhatsAppAccount::query()->first();

            if ($account === null) {
                return;
            }

            $account->forceFill(['status' => AccountStatus::Disconnected, 'token' => null])->save();

            Audit::record('whatsapp.disconnected', 'WhatsApp number disconnected', subject: $hotel, causer: $by, properties: [
                'display_phone' => $account->display_phone,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function detailsAttributes(array $details): array
    {
        $limit = $details['messaging_limit_tier'] ?? (is_array($details['throughput'] ?? null) ? ($details['throughput']['level'] ?? null) : null);

        return array_filter([
            'display_phone' => is_string($details['display_phone_number'] ?? null) ? $details['display_phone_number'] : null,
            'verified_name' => is_string($details['verified_name'] ?? null) ? $details['verified_name'] : null,
            'quality_rating' => is_string($details['quality_rating'] ?? null) ? $details['quality_rating'] : null,
            'messaging_limit' => is_string($limit) ? $limit : null,
        ], fn ($value): bool => $value !== null);
    }
}
