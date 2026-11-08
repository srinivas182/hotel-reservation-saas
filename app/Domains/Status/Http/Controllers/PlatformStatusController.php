<?php

declare(strict_types=1);

namespace App\Domains\Status\Http\Controllers;

use App\Domains\Onboarding\Models\CountryTemplate;
use App\Domains\Status\Models\StatusNotice;
use App\Domains\Status\Models\StatusSubscriber;
use App\Domains\Status\StatusBoard;
use App\Domains\Status\StatusNotices;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform → Status: post incidents and maintenance, update and resolve them.
 */
final class PlatformStatusController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/status', [
            'components' => collect(StatusBoard::components())->map(fn (string $state, string $c): array => ['key' => $c, 'label' => StatusNotice::componentLabel($c), 'state' => $state])->values(),
            'notices' => StatusNotice::query()->with('updates')->orderByRaw('resolved_at is null desc')->orderByDesc('starts_at')->limit(30)->get()->map(fn (StatusNotice $n): array => [
                'id' => $n->id, 'title' => $n->title, 'level' => $n->level, 'components' => $n->components, 'countries' => $n->countries,
                'starts_at' => $n->starts_at->toDateTimeString(), 'ends_at' => $n->ends_at?->toDateTimeString(), 'resolved_at' => $n->resolved_at?->toDateTimeString(),
                'updates' => $n->updates->map(fn ($u): array => ['state' => $u->state, 'body' => $u->body, 'at' => $u->created_at?->toDateTimeString()])->values(),
            ])->values(),
            'countries' => CountryTemplate::query()->orderBy('country_code')->pluck('country_code')->values(),
            'subscribers' => StatusSubscriber::query()->whereNotNull('confirmed_at')->count(),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, StatusNotices $notices): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'array'], 'title.fr' => ['required', 'string', 'max:150'], 'title.en' => ['required', 'string', 'max:150'],
            'body' => ['required', 'array'], 'body.fr' => ['required', 'string', 'max:2000'], 'body.en' => ['required', 'string', 'max:2000'],
            'level' => ['required', Rule::in(StatusNotice::LEVELS)],
            'components' => ['required', 'array', 'min:1'], 'components.*' => [Rule::in(StatusNotice::COMPONENTS)],
            'countries' => ['nullable', 'array'], 'countries.*' => ['string', 'size:2'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $notices->post(
            ['fr' => (string) $data['title']['fr'], 'en' => (string) $data['title']['en']], ['fr' => (string) $data['body']['fr'], 'en' => (string) $data['body']['en']],
            (string) $data['level'], array_values(array_map('strval', (array) $data['components'])), isset($data['countries']) ? array_values(array_map('strval', (array) $data['countries'])) : null,
            isset($data['starts_at']) ? CarbonImmutable::parse((string) $data['starts_at']) : CarbonImmutable::now(), isset($data['ends_at']) ? CarbonImmutable::parse((string) $data['ends_at']) : null, $user,
        );

        return back();
    }

    public function update(Request $request, StatusNotice $notice, #[CurrentUser] User $user, StatusNotices $notices): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'array'], 'body.fr' => ['required', 'string', 'max:2000'], 'body.en' => ['required', 'string', 'max:2000'],
            'state' => ['required', Rule::in(['investigating', 'identified', 'monitoring', 'resolved'])],
        ]);
        $notices->update($notice, ['fr' => (string) $data['body']['fr'], 'en' => (string) $data['body']['en']], (string) $data['state'], $user);

        return back();
    }
}
