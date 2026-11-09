<?php

declare(strict_types=1);

namespace App\Domains\Launch\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Launch\LaunchChecklist;
use App\Domains\Launch\LaunchWatch;
use App\Domains\Launch\Models\LaunchItem;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform → Launch: the checklist (I) and the launch-week watch (J).
 */
final class LaunchController extends Controller
{
    public function index(#[CurrentUser] User $user, LaunchChecklist $checklist): Response
    {
        $score = $checklist->refresh();
        $automatic = array_keys(LaunchChecklist::automatic());
        $role = $user->platform_role?->value;

        return Inertia::render('platform/launch', [
            'score' => $score,
            'version' => config('app.version'),
            'items' => LaunchItem::query()->with('doneBy')->orderBy('sort_order')->get()->map(fn (LaunchItem $i): array => [
                'id' => $i->id, 'key' => $i->key, 'area' => $i->area, 'title' => $i->title[app()->getLocale()] ?? $i->title['fr'], 'required' => $i->required,
                'owner_role' => $i->owner_role, 'status' => $i->status, 'proof' => $i->proof, 'done_by' => $i->doneBy?->name, 'done_at' => $i->done_at?->toDateString(),
                'due_on' => $i->due_on?->toDateString(), 'automatic' => in_array($i->key, $automatic, true),
                'can_edit' => ! in_array($i->key, $automatic, true) && ($role === 'super_admin' || $role === $i->owner_role),
            ])->values(),
            'watch' => LaunchWatch::build(),
        ]);
    }

    public function update(Request $request, LaunchItem $item, #[CurrentUser] User $user, LaunchChecklist $checklist): RedirectResponse
    {
        $role = $user->platform_role?->value;
        abort_unless($role === 'super_admin' || $role === $item->owner_role, 403);
        abort_if(array_key_exists($item->key, LaunchChecklist::automatic()) && $request->input('status') === 'done', 422);

        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'done', 'not_needed'])],
            'proof' => ['nullable', 'string', 'max:1000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if ($data['status'] === 'done' && trim((string) ($data['proof'] ?? '')) === '') {
            throw ValidationException::withMessages(['proof' => __('Add a link or a note as proof.')]);
        }
        if ($data['status'] === 'not_needed' && $item->required && $role !== 'super_admin') {
            abort(403);
        }

        $item->update([
            'status' => $data['status'], 'proof' => $data['proof'] ?? null, 'due_on' => $data['due_on'] ?? null,
            'done_by' => $data['status'] === 'done' ? $user->id : null, 'done_at' => $data['status'] === 'done' ? now() : null,
        ]);
        Audit::record('launch.item_updated', 'Launch checklist item updated', causer: $user, properties: ['item' => $item->key, 'status' => $item->status]);

        return back();
    }
}
