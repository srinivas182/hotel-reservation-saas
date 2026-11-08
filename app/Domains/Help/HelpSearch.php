<?php

declare(strict_types=1);

namespace App\Domains\Help;

use App\Domains\Help\Models\HelpArticle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Searching the help centre (titles first, then text), and recording the
 * searches so the platform sees what is missing.
 */
final class HelpSearch
{
    /**
     * @return Collection<int, HelpArticle>
     */
    public static function find(string $query, string $locale, string $source, int $limit = 8): Collection
    {
        $words = array_values(array_filter(preg_split('/\s+/', Str::lower(Str::ascii(trim($query)))) ?: [], fn (string $w): bool => mb_strlen($w) >= 3));

        if ($words === []) {
            return collect();
        }

        $results = HelpArticle::query()->where('published', true)->get()
            ->map(function (HelpArticle $a) use ($words, $locale): array {
                $title = Str::lower(Str::ascii($a->text('title', $locale)));
                $body = Str::lower(Str::ascii($a->text('body', $locale)));
                $score = 0;
                foreach ($words as $w) {
                    $score += (str_contains($title, $w) ? 5 : 0) + substr_count($body, $w);
                }

                return ['article' => $a, 'score' => $score];
            })
            ->filter(fn (array $r): bool => $r['score'] > 0)
            ->sortByDesc('score')->take($limit)->pluck('article')->values();

        DB::table('help_searches')->insert(['query' => mb_substr(trim($query), 0, 120), 'locale' => $locale, 'results' => $results->count(), 'source' => $source, 'created_at' => now()]);

        return $results;
    }
}
