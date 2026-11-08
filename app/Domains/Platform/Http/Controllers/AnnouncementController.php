<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Billing\Models\Plan;
use App\Domains\Platform\Announcements\Announcement;
use App\Domains\Tenancy\Models\Hotel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AnnouncementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/announcements', [
            'announcements' => Announcement::query()->latest('starts_at')->limit(100)->get()->map(fn (Announcement $a): array => [
                'id' => $a->id, 'title' => (object) $a->title, 'body' => (object) $a->body, 'level' => $a->level,
                'countries' => $a->countries ?? [], 'plans' => $a->plans ?? [], 'hotels' => $a->hotels ?? [],
                'starts_at' => $a->starts_at->format('Y-m-d\TH:i'), 'ends_at' => $a->ends_at->format('Y-m-d\TH:i'),
                'email' => $a->email, 'emailed' => $a->emailed_at !== null, 'live' => $a->starts_at->isPast() && $a->ends_at->isFuture(),
            ])->values(),
            'countries' => Hotel::query()->distinct()->orderBy('country_code')->pluck('country_code')->values(),
            'plans' => Plan::query()->orderBy('code')->pluck('code')->values(),
            'hotels' => Hotel::query()->orderBy('name')->get(['id', 'name'])->values(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $a = Announcement::query()->create([...$this->validated($request), 'created_by' => $user->id]);
        Audit::record('platform.announcement_created', 'Announcement created', causer: $user, properties: ['id' => $a->id, 'level' => $a->level]);

        return back();
    }

    public function update(Request $request, Announcement $announcement, #[CurrentUser] User $user): RedirectResponse
    {
        $announcement->update($this->validated($request));
        Audit::record('platform.announcement_updated', 'Announcement updated', causer: $user, properties: ['id' => $announcement->id]);

        return back();
    }

    public function destroy(Announcement $announcement, #[CurrentUser] User $user): RedirectResponse
    {
        $announcement->delete();
        Audit::record('platform.announcement_deleted', 'Announcement deleted', causer: $user, properties: ['id' => $announcement->id]);

        return back();
    }

    /**
     * Hotel side: hide an info or warning banner for this user.
     */
    public function dismiss(Announcement $announcement, #[CurrentUser] User $user): RedirectResponse
    {
        abort_if($announcement->level === 'maintenance', 403);
        DB::table('announcement_dismissals')->insertOrIgnore(['announcement_id' => $announcement->id, 'user_id' => $user->id, 'created_at' => now()]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'array'], 'title.fr' => ['required', 'string', 'max:150'], 'title.en' => ['nullable', 'string', 'max:150'],
            'body' => ['required', 'array'], 'body.fr' => ['required', 'string', 'max:2000'], 'body.en' => ['nullable', 'string', 'max:2000'],
            'level' => ['required', Rule::in(['info', 'warning', 'maintenance'])],
            'countries' => ['array'], 'countries.*' => ['string', 'size:2'],
            'plans' => ['array'], 'plans.*' => ['string', 'max:40'],
            'hotels' => ['array'], 'hotels.*' => ['string', Rule::exists('hotels', 'id')],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after:starts_at'],
            'email' => ['required', 'boolean'],
        ]);

        return [
            'title' => array_filter((array) $data['title']), 'body' => array_filter((array) $data['body']), 'level' => $data['level'],
            'countries' => array_values(array_map('strtoupper', (array) ($data['countries'] ?? []))), 'plans' => array_values((array) ($data['plans'] ?? [])), 'hotels' => array_values((array) ($data['hotels'] ?? [])),
            'starts_at' => CarbonImmutable::parse((string) $data['starts_at']), 'ends_at' => CarbonImmutable::parse((string) $data['ends_at']), 'email' => (bool) $data['email'],
        ];
    }
}
