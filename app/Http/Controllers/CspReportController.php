<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * Receives Content Security Policy violation reports from browsers.
 */
final class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $report = json_decode((string) $request->getContent(), true);
        $details = is_array($report) && is_array($report['csp-report'] ?? null) ? $report['csp-report'] : [];

        Log::warning('CSP violation', Arr::only($details, [
            'document-uri', 'violated-directive', 'effective-directive', 'blocked-uri', 'source-file', 'line-number',
        ]));

        // S16.4: one row per directive and blocked source, to see when enforcing is safe.
        $directive = mb_substr((string) ($details['effective-directive'] ?? $details['violated-directive'] ?? 'unknown'), 0, 60);
        $blockedUri = (string) ($details['blocked-uri'] ?? '');
        $host = parse_url($blockedUri, PHP_URL_HOST);
        $keyword = strtok($blockedUri, ':/');
        $blocked = mb_substr(is_string($host) && $host !== '' ? $host : (is_string($keyword) ? $keyword : 'unknown'), 0, 191);
        $page = mb_substr((string) (parse_url((string) ($details['document-uri'] ?? ''), PHP_URL_PATH) ?? ''), 0, 191);
        try {
            \Illuminate\Support\Facades\DB::table('csp_reports')->upsert(
                [['directive' => $directive, 'blocked' => $blocked, 'page' => $page, 'count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]],
                ['directive', 'blocked'],
                ['count' => \Illuminate\Support\Facades\DB::raw('count + 1'), 'last_seen_at' => now()],
            );
        } catch (\Throwable) {
            // Reports are best effort.
        }

        return response()->noContent();
    }
}
