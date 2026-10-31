<?php

declare(strict_types=1);

namespace App\Domains\Audit\Http\Controllers;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only view of the current hotel's audit log.
 */
final class HotelAuditController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(Request $request): Response
    {
        $hotel = $this->context->hotel();

        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:60'],
            'user' => ['nullable', 'string', 'size:26'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        /** @var Builder<AuditLog> $query */
        $query = AuditLog::query()->where('hotel_id', $hotel->getKey());

        $events = (clone $query)->distinct()->orderBy('event')->pluck('event')->filter()->values();

        $entries = $query
            ->with('causer')
            ->when($filters['event'] ?? null, fn (Builder $q, string $event) => $q->where('event', $event))
            ->when($filters['user'] ?? null, fn (Builder $q, string $user) => $q->where('causer_id', $user))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', now($hotel->timezone)->parse($from)->startOfDay()->utc()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', now($hotel->timezone)->parse($to)->endOfDay()->utc()))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AuditLog $entry): array => [
                'id' => $entry->id,
                'event' => $entry->event,
                'description' => __((string) $entry->description),
                'user' => $entry->causer instanceof User ? $entry->causer->name : null,
                'ip' => $entry->ip,
                'properties' => $entry->properties?->except(['attributes'])->all() ?? [],
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);

        return Inertia::render('hotel/audit', [
            'entries' => $entries,
            'events' => $events,
            'people' => $hotel->members()->orderBy('first_name')->get()
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])->values(),
            'filters' => [
                'event' => $filters['event'] ?? '',
                'user' => $filters['user'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
        ]);
    }
}
