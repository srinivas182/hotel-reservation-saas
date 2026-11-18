<?php

declare(strict_types=1);

namespace App\Domains\StaffActions;

use RuntimeException;

/**
 * A scheduled decision could not be carried out; the message is sent to
 * the person who made it.
 */
final class StaffActionFailed extends RuntimeException {}
