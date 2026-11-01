<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Exceptions;

use RuntimeException;

/**
 * Connecting a number did not complete. The message is shown to the owner.
 */
final class WhatsAppConnectionFailed extends RuntimeException {}
