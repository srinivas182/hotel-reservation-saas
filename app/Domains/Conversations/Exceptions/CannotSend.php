<?php

declare(strict_types=1);

namespace App\Domains\Conversations\Exceptions;

use DomainException;

/**
 * A message cannot be sent now. The message is shown to staff.
 */
final class CannotSend extends DomainException {}
