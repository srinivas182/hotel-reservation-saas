<?php

declare(strict_types=1);

namespace App\Domains\Product\Http\Controllers;

use App\Domains\Product\Ideas;
use App\Domains\Product\Models\Idea;
use App\Domains\Product\Models\IdeaVote;
use App\Domains\Product\Models\ReleaseNote;
use App\Domains\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E: "What's new" and F: the ideas board, for everyone signed in.
 */
final class ProductController extends Controller
{
    public function whatsNew(#[CurrentUser] User $user): Response
    {
        $locale = app()->getLocale();
        $seen = $user->whats_new_seen_at;
        $user->forceFill(['whats_new_seen_at' => now()])->save();

        return Inertia::render('whats-new', [
            'notes' => ReleaseNote::query()->published()->orderByDesc('published_at')->limit(30)->get()->map(fn (ReleaseNote $n): array => [
                'id' => $n->id, 'version' => $n->version, 'title' => $n->text('title', $locale), 'html' => $n->html($locale),
                'media' => $n->media_path === null ? null : route('whats-new.media', basename($n->media_path)),
                'try' => $n->try_path, 'date' => $n->published_at?->toDateString(), 'new' => $seen === null || ($n->published_at !== null && $n->published_at->greaterThan($seen)),
            ])->values(),
            'productEmails' => $user->product_emails,
        ]);
    }

    public function emails(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->forceFill(['product_emails' => (bool) $request->validate(['on' => ['required', 'boolean']])['on']])->save();

        return back();
    }

    /** From the link in product emails, without signing in. */
    public function emailsOff(User $user): Response
    {
        $user->forceFill(['product_emails' => false])->save();

        return Inertia::render('auth/product-emails-off');
    }

    public function media(string $file): StreamedResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9-]+\.(png|jpg|jpeg|gif|webp)$/', $file) === 1 && Storage::disk('local')->exists('whats-new/'.$file), 404);

        return Storage::disk('local')->response('whats-new/'.$file, null, ['Cache-Control' => 'public, max-age=86400', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    public function ideas(Request $request, #[CurrentUser] User $user): Response
    {
        $status = (string) $request->query('status', 'open');
        $mine = IdeaVote::query()->where('user_id', $user->id)->pluck('idea_id')->all();

        return Inertia::render('ideas', [
            'filter' => $status,
            'ideas' => Idea::query()->whereNull('merged_into_id')
                ->when($status === 'open', fn ($q) => $q->whereIn('status', ['new', 'planned', 'in_progress']))
                ->when(in_array($status, Idea::STATUSES, true), fn ($q) => $q->where('status', $status))
                ->orderByDesc('votes_count')->orderByDesc('id')->limit(100)->get()
                ->map(fn (Idea $i): array => [
                    'id' => $i->id, 'title' => $i->title, 'body' => $i->body, 'category' => $i->category, 'status' => $i->status, 'status_label' => Idea::statusLabel($i->status),
                    'votes' => $i->votes_count, 'voted' => in_array($i->id, $mine, true), 'reply' => $i->reply, 'date' => $i->created_at?->toDateString(),
                ])->values(),
            'categories' => Idea::CATEGORIES,
        ]);
    }

    public function similar(Request $request, Ideas $ideas): JsonResponse
    {
        return response()->json(['ideas' => $ideas->similar((string) $request->query('q', ''))]);
    }

    public function suggest(Request $request, #[CurrentUser] User $user, Ideas $ideas): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:5', 'max:150'],
            'body' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(Idea::CATEGORIES)],
        ]);
        $ideas->suggest($user, $this->hotelId(), (string) $data['title'], $data['body'] ?? null, (string) $data['category']);

        return back();
    }

    public function vote(Idea $idea, #[CurrentUser] User $user, Ideas $ideas): RedirectResponse
    {
        abort_if($idea->merged_into_id !== null || in_array($idea->status, ['done', 'declined'], true), 422);
        $ideas->vote($idea, $user, $this->hotelId());

        return back();
    }

    private function hotelId(): ?string
    {
        $context = app(TenantContext::class);

        return $context->has() ? $context->id() : null;
    }
}
