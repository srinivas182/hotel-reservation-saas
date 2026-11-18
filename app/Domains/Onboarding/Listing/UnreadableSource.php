<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listing;

use RuntimeException;

/**
 * A link that could not or must not be read. The message is shown to the
 * owner, so it says what to do instead.
 */
final class UnreadableSource extends RuntimeException {}
