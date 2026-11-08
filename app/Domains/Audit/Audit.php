<?php

declare(strict_types=1);

namespace App\Domains\Audit;

use App\Domains\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Single entry point for writing audit entries.
 *
 * Event names are dotted and stable (for example "auth.login",
 * "hotel.updated", "tenancy.bypass") so they can be filtered and reported.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $event,
        string $description,
        ?Model $subject = null,
        ?Model $causer = null,
        array $properties = [],
    ): AuditLog {
        // Actions taken in a support session are marked with it.
        $support = app(\App\Domains\Platform\Support\SupportAccess::class)->current();
        if ($support !== null && ! isset($properties['support_session'])) {
            $properties['support_session'] = $support->id;
        }

        $logger = activity('audit')
            ->event($event)
            ->withProperties($properties);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        /** @var AuditLog $entry */
        $entry = $logger->log($description);

        return $entry;
    }
}
