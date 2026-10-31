<?php

declare(strict_types=1);

namespace App\Domains\Access;

use App\Domains\Access\Exceptions\StaffRuleViolation;
use App\Domains\Access\Mail\StaffInvitationMail;
use App\Domains\Access\Models\HotelInvitation;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Audit;
use App\Domains\Tenancy\Models\Hotel;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invites people to a hotel by email and turns accepted invitations into
 * memberships.
 */
final class InvitationService
{
    public const VALID_DAYS = 7;

    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffService $staff,
    ) {}

    public function invite(Hotel $hotel, User $actor, string $email, Role $role): HotelInvitation
    {
        $email = Str::lower(trim($email));

        abort_unless($role->hotel_id === $hotel->getKey(), 404);

        if ($role->isOwner() && ! $this->staff->isOwner($hotel, $actor)) {
            throw StaffRuleViolation::ownersOnly();
        }

        return $this->context->run($hotel, function () use ($hotel, $actor, $email, $role): HotelInvitation {
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null && $this->staff->isActiveMember($hotel, $existing)) {
                throw StaffRuleViolation::alreadyMember();
            }

            $pending = HotelInvitation::query()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->exists();

            if ($pending) {
                throw StaffRuleViolation::alreadyInvited();
            }

            $token = Str::random(48);

            $invitation = HotelInvitation::create([
                'email' => $email,
                'role_id' => $role->getKey(),
                'token_hash' => HotelInvitation::hashToken($token),
                'invited_by' => $actor->getKey(),
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ]);

            $this->send($hotel, $invitation, $token);

            Audit::record('staff.invited', 'Staff invited', subject: $hotel, properties: [
                'invitation_id' => $invitation->getKey(),
                'email' => $email,
                'role' => $role->name,
            ]);

            return $invitation;
        });
    }

    public function resend(Hotel $hotel, HotelInvitation $invitation): void
    {
        abort_if($invitation->accepted_at !== null || $invitation->revoked_at !== null, 422, __('This invitation can no longer be resent.'));

        $token = Str::random(48);

        $this->context->run($hotel, function () use ($invitation, $token): void {
            $invitation->forceFill([
                'token_hash' => HotelInvitation::hashToken($token),
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ])->save();
        });

        $this->send($hotel, $invitation, $token);

        Audit::record('staff.invitation_resent', 'Invitation resent', subject: $hotel, properties: ['invitation_id' => $invitation->getKey()]);
    }

    public function revoke(Hotel $hotel, HotelInvitation $invitation): void
    {
        $this->context->run($hotel, fn () => $invitation->forceFill(['revoked_at' => now()])->save());

        Audit::record('staff.invitation_revoked', 'Invitation revoked', subject: $hotel, properties: ['invitation_id' => $invitation->getKey()]);
    }

    /**
     * Find an invitation from the token in an email link. The link is opened
     * before any hotel is selected, so this is an audited cross-hotel lookup.
     */
    public function findByToken(string $token): ?HotelInvitation
    {
        return $this->context->bypass('Invitation link lookup', fn (): ?HotelInvitation => HotelInvitation::query()
            ->with(['hotel', 'role'])
            ->where('token_hash', HotelInvitation::hashToken($token))
            ->first());
    }

    public function accept(HotelInvitation $invitation, User $user): void
    {
        abort_unless($invitation->isPending(), 410);
        abort_unless(Str::lower($user->email) === $invitation->email, 403);

        /** @var Hotel $hotel */
        $hotel = $invitation->hotel;
        /** @var Role $role */
        $role = $invitation->role;

        DB::transaction(function () use ($invitation, $user, $hotel, $role): void {
            $this->staff->assign($hotel, $user, $role);

            $this->context->run($hotel, fn () => $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_by' => $user->getKey(),
            ])->save());
        });

        Audit::record('staff.invitation_accepted', 'Invitation accepted', subject: $hotel, causer: $user, properties: [
            'invitation_id' => $invitation->getKey(),
            'role' => $role->name,
        ]);
    }

    private function send(Hotel $hotel, HotelInvitation $invitation, string $token): void
    {
        Mail::to($invitation->email)
            ->locale($hotel->default_locale)
            ->send(new StaffInvitationMail(
                hotelName: $hotel->name,
                roleName: $invitation->role()->firstOrFail()->displayName(),
                url: route('invitations.show', $token),
                expiresAt: $invitation->expires_at,
            ));
    }
}
