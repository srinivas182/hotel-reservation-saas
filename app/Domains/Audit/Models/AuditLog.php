<?php

declare(strict_types=1);

namespace App\Domains\Audit\Models;

use App\Domains\Audit\Exceptions\ImmutableAuditLog;
use App\Domains\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Append-only audit entry. Each row carries the hotel (null for
 * platform-level events), the request ID, IP address and browser.
 *
 * @property string|null $hotel_id
 * @property string|null $request_id
 * @property string|null $ip
 * @property string|null $user_agent
 */
class AuditLog extends Activity
{
    protected static function booted(): void
    {
        static::creating(function (AuditLog $log): void {
            $log->hotel_id ??= app(TenantContext::class)->idOrNull();

            $requestId = Context::get('request_id');
            $log->request_id ??= is_string($requestId) ? $requestId : null;

            if (! app()->runningInConsole() || app()->runningUnitTests()) {
                $request = request();
                $log->ip ??= $request->ip();
                $log->user_agent ??= Str::limit((string) $request->userAgent(), 509);
            }
        });

        static::updating(fn () => throw ImmutableAuditLog::cannotUpdate());
        static::deleting(fn () => throw ImmutableAuditLog::cannotDelete());
    }
}
