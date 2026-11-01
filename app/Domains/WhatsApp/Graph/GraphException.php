<?php

declare(strict_types=1);

namespace App\Domains\WhatsApp\Graph;

use RuntimeException;

/**
 * An error returned by Meta's Graph API.
 */
final class GraphException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $metaCode = 0,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message, $metaCode);
    }

    /**
     * Meta's code for an expired or revoked access token.
     */
    public function isInvalidToken(): bool
    {
        return $this->metaCode === 190;
    }
}
