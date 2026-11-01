<?php

declare(strict_types=1);

namespace App\Domains\Bookings\Exceptions;

use DomainException;

/**
 * A booking action that is not possible in the booking's current state.
 * The message is shown to staff.
 */
final class BookingActionNotAllowed extends DomainException {}
