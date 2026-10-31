<?php

declare(strict_types=1);

namespace App\Domains\Audit\Exceptions;

use LogicException;

final class ImmutableAuditLog extends LogicException
{
    public static function cannotUpdate(): self
    {
        return new self('Audit log entries are append-only and cannot be updated.');
    }

    public static function cannotDelete(): self
    {
        return new self('Audit log entries are append-only and cannot be deleted.');
    }
}
