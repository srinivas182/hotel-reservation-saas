<?php

declare(strict_types=1);

namespace App\Domains\Access\Exceptions;

use DomainException;

/**
 * A staff change that would break a safety rule (for example leaving a
 * hotel without an owner). The message is shown to the user.
 */
final class StaffRuleViolation extends DomainException
{
    public static function selfChange(): self
    {
        return new self(__('You cannot change your own role or access.'));
    }

    public static function lastOwner(): self
    {
        return new self(__('A hotel must always have at least one active owner.'));
    }

    public static function ownersOnly(): self
    {
        return new self(__('Only owners can give or take away the Owner role.'));
    }

    public static function primaryOwner(): self
    {
        return new self(__('Make someone else the primary owner before removing this person.'));
    }

    public static function notOwner(): self
    {
        return new self(__('The primary owner must hold the Owner role.'));
    }

    public static function alreadyMember(): self
    {
        return new self(__('This person is already a member of the hotel.'));
    }

    public static function alreadyInvited(): self
    {
        return new self(__('An invitation is already pending for this email. Resend it instead.'));
    }

    public static function roleInUse(): self
    {
        return new self(__('Move staff to another role before deleting this one.'));
    }
}
