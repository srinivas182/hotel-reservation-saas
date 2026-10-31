<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

final class HotelReassignmentForbidden extends RuntimeException
{
    public static function for(string $model): self
    {
        return new self("Records of [{$model}] cannot be moved to another hotel.");
    }
}
