<?php

declare(strict_types=1);

namespace App\Domains\Product\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Product\Ideas;
use App\Domains\Product\Models\Idea;
use App\Domains\Product\Models\ReleaseNote;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform → What's new and Platform → Ideas.
 */
final class PlatformProductController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('platform/product', [
            'notes' => ReleaseNote::query()->orderByDesc('id')->limit(50)->get()->map(fn (ReleaseNote $n): array => [
                'id' => $n->id, 'version' => $n->version, 'title' => $n->title, 'body' => $n->body, 'try_path' => $n->try_path,
                'media' => $n->media_path === null ? null : route('whats-new.media', basename($n->media_path)), 'published_at' => $n->published_at?->toDateTimeString(),
            ])->values(),
            'ideas' => Idea::query()->whereNull('merged_into_id')->orderByRaw("case status when 'new' then 0 when 'planned' then 1 when 'in_progress' then 2 else 3 end")->orderByDesc('votes_count')->limit(200)->get()
                ->map(fn (Idea $i): array => [
                    'id' => $i->id, 'title' => $i->title, 'body' => $i->body, 'category' => $i->category, 'status' => $i->status, 'votes' => $i->votes_count, 'reply' => $i->reply,
                    'hotels' => DB::table('idea_votes')->where('idea_id', $i->id)->whereNotNull('hotel_id')->distinct()->count('hotel_id'),
                ])->values(),
            'statuses' => Idea::STATUSES,
            'version' => config('app.version'),
        ]);
    }

    public function saveNote(Request $request, #[CurrentUser] User $user, ?ReleaseNote $note = null): RedirectResponse
    {
        $data = $request->validate([
            'version' => ['nullable', 'string', 'max:20'],
            'title' => ['required', 'array'], 'title.fr' => ['required', 'string', 'max:150'], 'title.en' => ['required', 'string', 'max:150'],
            'body' => ['required', 'array'], 'body.fr' => ['required', 'string', 'max:5000'], 'body.en' => ['required', 'string', 'max:5000'],
            'try_path' => ['nullable', 'string', 'max:255', 'regex:#^/[A-Za-z0-9/_-]*$#'],
            'publish' => ['nullable', 'boolean'],
            'media' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:5120'],
        ]);

        $values = ['version' => $data['version'] ?? null, 'title' => $data['title'], 'body' => $data['body'], 'try_path' => $data['try_path'] ?? null];
        $file = $request->file('media');
        if ($file instanceof UploadedFile) {
            $name = Str::uuid().'.'.strtolower((string) $file->extension());
            Storage::disk('local')->putFileAs('whats-new', $file, $name);
            $values['media_path'] = 'whats-new/'.$name;
        }

        $note = $note === null ? ReleaseNote::query()->create($values) : tap($note)->update($values);
        if ((bool) ($data['publish'] ?? false) && $note->published_at === null) {
            $note->update(['published_at' => now()]);
        }
        Audit::record('product.note_saved', 'Release note saved', causer: $user, properties: ['note' => $note->id, 'published' => $note->published_at !== null]);

        return back();
    }

    public function storeNote(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveNote($request, $user);
    }

    public function updateNote(Request $request, ReleaseNote $note, #[CurrentUser] User $user): RedirectResponse
    {
        return $this->saveNote($request, $user, $note);
    }

    public function decide(Request $request, Idea $idea, #[CurrentUser] User $user, Ideas $ideas): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(Idea::STATUSES)], 'reply' => ['nullable', 'string', 'max:2000'], 'merge_into' => ['nullable', 'integer', Rule::exists('ideas', 'id')]]);

        if (isset($data['merge_into']) && (int) $data['merge_into'] !== $idea->id) {
            $ideas->merge($idea, Idea::query()->whereKey((int) $data['merge_into'])->firstOrFail(), $user);
        } else {
            $ideas->decide($idea, (string) $data['status'], $data['reply'] ?? null, $user);
        }

        return back();
    }
}
