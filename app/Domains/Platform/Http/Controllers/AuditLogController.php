<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Platform\PlatformAbility;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit log across every hotel and the platform itself.
 */
final class AuditLogController extends Controller
{
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $filters = $this->filters($request);
        $page = $this->query($filters)->latest('id')->paginate(50)->withQueryString();

        return Inertia::render('platform/audit', [
            'filters' => $filters,
            'hotels' => Hotel::query()->orderBy('name')->get(['id', 'name'])->values(),
            'entries' => collect($page->items())->map(fn (AuditLog $a): array => $this->row($a))->values(),
            'pagination' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
            'canExport' => $user->platformCan(PlatformAbility::AuditExport),
        ]);
    }

    public function export(Request $request, #[CurrentUser] User $user): StreamedResponse
    {
        $filters = $this->filters($request);
        Audit::record('platform.audit_exported', 'Audit log exported', causer: $user, properties: $filters);

        return response()->streamDownload(function () use ($filters): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'at', 'event', 'description', 'hotel_id', 'by', 'ip', 'properties']);
            $this->query($filters)->orderBy('id')->chunk(500, function ($rows) use ($out): void {
                foreach ($rows as $a) {
                    /** @var AuditLog $a */
                    $r = $this->row($a);
                    fputcsv($out, [$r['id'], $r['at'], $r['event'], $r['description'], $r['hotel_id'], $r['by'], $r['ip'], json_encode($r['properties'])]);
                }
            });
            fclose($out);
        }, 'audit-log.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{hotel: string|null, user: string|null, event: string|null, from: string|null, to: string|null}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'hotel' => ['nullable', 'string', 'max:26'], 'user' => ['nullable', 'string', 'max:191'], 'event' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return ['hotel' => $data['hotel'] ?? null, 'user' => $data['user'] ?? null, 'event' => $data['event'] ?? null, 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null];
    }

    /**
     * @param  array{hotel: string|null, user: string|null, event: string|null, from: string|null, to: string|null}  $f
     * @return Builder<AuditLog>
     */
    private function query(array $f): Builder
    {
        return AuditLog::query()->with('causer')
            ->when($f['hotel'] !== null, fn ($q) => $q->where('hotel_id', $f['hotel']))
            ->when($f['event'] !== null, fn ($q) => $q->where('event', 'like', str_replace(['%', '_'], ['\%', '\_'], (string) $f['event']).'%'))
            ->when($f['user'] !== null, function ($q) use ($f): void {
                $ids = User::query()->where('email', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $f['user']).'%')->pluck('id');
                $q->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', $ids);
            })
            ->when($f['from'] !== null, fn ($q) => $q->where('created_at', '>=', $f['from'].' 00:00:00'))
            ->when($f['to'] !== null, fn ($q) => $q->where('created_at', '<=', $f['to'].' 23:59:59'));
    }

    /**
     * @return array{id: int, at: string|null, event: string|null, description: string, hotel_id: string|null, by: string|null, ip: string|null, properties: mixed}
     */
    private function row(AuditLog $a): array
    {
        return [
            'id' => (int) $a->id, 'at' => $a->created_at?->toDateTimeString(), 'event' => $a->event, 'description' => (string) __((string) $a->description),
            'hotel_id' => $a->hotel_id, 'by' => $a->causer instanceof User ? $a->causer->email : null, 'ip' => $a->ip, 'properties' => $a->properties?->toArray(),
        ];
    }
}
