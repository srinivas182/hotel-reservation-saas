<?php

declare(strict_types=1);

namespace App\Domains\StaffActions;

use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Models\User;

/**
 * Something staff can answer with WhatsApp buttons (booking requests in
 * Sprint B; table requests and orders can follow). The router has already
 * checked the signature, the phone and that the person is still a member.
 */
interface StaffActionHandler
{
    /** The action prefix this handler owns, e.g. "br". */
    public function prefix(): string;

    /** The kind stored on staff actions, e.g. "booking_request". */
    public function kind(): string;

    /** A button was tapped: reply, ask a follow-up question, or schedule the decision. */
    public function tap(ActionToken $token, User $user, StaffAlertSettings $settings): void;

    /** Carry out a scheduled decision; returns the message for the person who made it. */
    public function execute(StaffAction $action, User $user): string;
}
