<?php

declare(strict_types=1);

namespace App\Domains\Events\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Events\EventStatus;
use App\Domains\Events\Models\Event;
use App\Domains\Events\Models\EventSheet;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The event sheet for the teams. Every save is a new version; versions
 * saved after confirmation are marked so teams see what changed.
 */
final class EventSheetController extends Controller
{
    public function save(Request $request, Event $event, #[CurrentUser] User $user): RedirectResponse
    {
        $data = $request->validate([
            'timings' => ['array', 'max:40'],
            'timings.*.time' => ['required', 'date_format:H:i'],
            'timings.*.what' => ['required', 'string', 'max:200'],
            'menu' => ['nullable', 'string', 'max:3000'],
            'equipment' => ['nullable', 'string', 'max:2000'],
            'kitchen' => ['nullable', 'string', 'max:2000'],
            'setup' => ['nullable', 'string', 'max:2000'],
            'reception' => ['nullable', 'string', 'max:2000'],
        ]);

        $version = ((int) EventSheet::query()->where('event_id', $event->id)->max('version')) + 1;
        $timings = collect((array) ($data['timings'] ?? []))->sortBy('time')->values()->all();

        EventSheet::query()->create([
            'event_id' => $event->id,
            'version' => $version,
            'content' => [
                'timings' => $timings, 'layout' => $event->layout, 'people' => $event->people,
                'menu' => $data['menu'] ?? null, 'equipment' => $data['equipment'] ?? null,
                'kitchen' => $data['kitchen'] ?? null, 'setup' => $data['setup'] ?? null, 'reception' => $data['reception'] ?? null,
            ],
            'after_confirmation' => $event->status === EventStatus::Confirmed,
            'changed_by' => $user->getKey(),
        ]);

        Audit::record('events.sheet_saved', 'Event sheet saved', subject: $event, causer: $user, properties: ['version' => $version]);

        return back();
    }

    public function print(Event $event, TenantContext $context): View
    {
        $event->load(['space', 'block']);
        $sheets = EventSheet::query()->with('author')->where('event_id', $event->id)->orderByDesc('version')->get();
        $locale = $context->hotel()->default_locale;

        return view('events.sheet', [
            'hotel' => $context->hotel(),
            'event' => $event,
            'space' => $event->space?->localizedName($locale, $locale),
            'block' => $event->block?->localizedName($locale, $locale),
            'sheet' => $sheets->first(),
            'changes' => $sheets->where('after_confirmation', true)->count(),
        ]);
    }
}
