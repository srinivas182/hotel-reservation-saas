<?php

declare(strict_types=1);

namespace App\Domains\Platform\Support;

use App\Domains\Access\Permission;
use App\Domains\Audit\Audit;
use App\Domains\Hotel\Models\HotelSettings;
use App\Domains\Platform\Support\Mail\SupportSessionMail;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Support sessions: started with a reason, read-only or with changes,
 * 30 minutes (extendable once), visible to the hotel, never able to touch
 * the owner's login, roles, WhatsApp connection or billing.
 */
final class SupportAccess
{
    public const SESSION_KEY = 'support_session_id';

    /** Hotel permissions a support session never has. */
    public const NEVER = [Permission::RolesManage, Permission::WhatsAppConnect, Permission::BillingManage, Permission::StaffManage];

    private ?SupportSession $current = null;

    public function __construct(private readonly TenantContext $context) {}

    public function start(Hotel $hotel, User $by, string $reason, string $mode): SupportSession
    {
        $askFirst = $this->context->run($hotel, fn (): bool => HotelSettings::current()->support_access === 'ask_first');

        $session = SupportSession::query()->create([
            'hotel_id' => $hotel->id, 'user_id' => $by->id, 'reason' => $reason, 'mode' => $mode,
            'status' => $askFirst ? 'pending' : 'active',
            'started_at' => $askFirst ? null : now(), 'expires_at' => $askFirst ? null : now()->addMinutes(SupportSession::MINUTES),
        ]);

        $this->context->run($hotel, fn () => Audit::record('support.session_'.($askFirst ? 'requested' : 'started'), $askFirst ? 'Support session requested' : 'Support session started', subject: $hotel, causer: $by, properties: ['reason' => $reason, 'mode' => $mode, 'session' => $session->id]));

        if ($hotel->owner?->email !== null) {
            Mail::to($hotel->owner->email)->queue(new SupportSessionMail($session->id, $askFirst ? 'approval' : 'started'));
        }

        return $session;
    }

    public function approve(SupportSession $session, User $owner, bool $approve): SupportSession
    {
        if ($session->status !== 'pending') {
            throw new RuntimeException('Not pending.');
        }

        $session->forceFill($approve
            ? ['status' => 'active', 'started_at' => now(), 'expires_at' => now()->addMinutes(SupportSession::MINUTES), 'approved_by' => $owner->id]
            : ['status' => 'refused', 'ended_at' => now(), 'approved_by' => $owner->id])->save();

        Audit::record($approve ? 'support.session_approved' : 'support.session_refused', $approve ? 'Support session approved' : 'Support session refused', subject: $session->hotel, causer: $owner, properties: ['session' => $session->id]);

        return $session;
    }

    public function extend(SupportSession $session, User $by): SupportSession
    {
        if (! $session->isLive() || $session->extended || $session->user_id !== $by->id) {
            throw new RuntimeException((string) __('This session cannot be extended.'));
        }

        $session->forceFill(['expires_at' => $session->expires_at?->addMinutes(SupportSession::MINUTES), 'extended' => true])->save();
        Audit::record('support.session_extended', 'Support session extended', subject: $session->hotel, causer: $by, properties: ['session' => $session->id]);

        return $session;
    }

    public function end(SupportSession $session, User $by): void
    {
        if ($session->status === 'active' || $session->status === 'pending') {
            $session->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            Audit::record('support.session_ended', 'Support session ended', subject: $session->hotel, causer: $by, properties: ['session' => $session->id]);
        }
    }

    public function setCurrent(?SupportSession $session): void
    {
        $this->current = $session;
    }

    public function current(): ?SupportSession
    {
        return $this->current;
    }

    /**
     * Whether the current support session allows a hotel permission.
     */
    public function allows(string $permission): bool
    {
        $session = $this->current;

        if ($session === null) {
            return false;
        }

        $value = Permission::tryFrom($permission);

        if ($value === null || in_array($value, self::NEVER, true)) {
            return false;
        }

        return $session->mode === 'write' || str_ends_with($permission, '.view');
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_filter(array_map(fn (Permission $p): string => $p->value, Permission::cases()), fn (string $p): bool => $this->allows($p)));
    }
}
