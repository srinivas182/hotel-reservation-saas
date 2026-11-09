<?php

declare(strict_types=1);

namespace App\Domains\Product;

use App\Domains\Audit\Audit;
use App\Domains\Product\Mail\IdeaUpdateMail;
use App\Domains\Product\Models\Idea;
use App\Domains\Product\Models\IdeaVote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * F: ideas — suggest (with similar ideas shown first), vote, and the
 * platform's decisions, with voters told when an idea moves forward.
 */
final class Ideas
{
    public function suggest(User $user, ?string $hotelId, string $title, ?string $body, string $category): Idea
    {
        $idea = Idea::query()->create(['title' => $title, 'body' => $body, 'category' => $category, 'author_id' => $user->id, 'hotel_id' => $hotelId]);
        $this->vote($idea, $user, $hotelId);

        return $idea->refresh();
    }

    /**
     * @return array<int, array{id: int, title: string, votes: int, status: string}>
     */
    public function similar(string $text): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', Str::lower(Str::ascii($text))) ?: [], fn (string $w): bool => mb_strlen($w) >= 4));
        if ($words === []) {
            return [];
        }

        return Idea::query()->whereNull('merged_into_id')->get()
            ->map(fn (Idea $i): array => ['idea' => $i, 'score' => count(array_filter($words, fn (string $w): bool => str_contains(Str::lower(Str::ascii($i->title.' '.$i->body)), $w)))])
            ->filter(fn (array $r): bool => $r['score'] > 0)->sortByDesc('score')->take(3)
            ->map(fn (array $r): array => ['id' => $r['idea']->id, 'title' => $r['idea']->title, 'votes' => $r['idea']->votes_count, 'status' => $r['idea']->status])->values()->all();
    }

    public function vote(Idea $idea, User $user, ?string $hotelId): bool
    {
        return DB::transaction(function () use ($idea, $user, $hotelId): bool {
            $existing = IdeaVote::query()->where('idea_id', $idea->id)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing !== null) {
                $existing->delete();
                $idea->decrement('votes_count');

                return false;
            }
            IdeaVote::query()->create(['idea_id' => $idea->id, 'user_id' => $user->id, 'hotel_id' => $hotelId]);
            $idea->increment('votes_count');

            return true;
        });
    }

    public function decide(Idea $idea, string $status, ?string $reply, User $by): void
    {
        $before = $idea->status;
        $idea->update(['status' => $status, 'reply' => $reply]);
        Audit::record('ideas.decided', 'Idea status changed', causer: $by, properties: ['idea' => $idea->id, 'status' => $status]);

        if ($before !== $status && in_array($status, ['planned', 'done'], true)) {
            User::query()->whereIn('id', IdeaVote::query()->where('idea_id', $idea->id)->select('user_id'))->where('product_emails', true)->get()
                ->each(fn (User $u) => Mail::to($u->email)->queue(new IdeaUpdateMail($idea->id, $u->id)));
        }
    }

    /** Duplicates: votes move to the kept idea (no one counted twice). */
    public function merge(Idea $duplicate, Idea $into, User $by): void
    {
        DB::transaction(function () use ($duplicate, $into): void {
            foreach (IdeaVote::query()->where('idea_id', $duplicate->id)->get() as $vote) {
                $already = IdeaVote::query()->where('idea_id', $into->id)->where('user_id', $vote->user_id)->exists();
                $already ? $vote->delete() : $vote->update(['idea_id' => $into->id]);
            }
            $into->update(['votes_count' => IdeaVote::query()->where('idea_id', $into->id)->count()]);
            $duplicate->update(['merged_into_id' => $into->id, 'votes_count' => 0, 'status' => 'declined', 'reply' => __('Merged with “:title”.', ['title' => $into->title])]);
        });
        Audit::record('ideas.merged', 'Ideas merged', causer: $by, properties: ['from' => $duplicate->id, 'into' => $into->id]);
    }
}
