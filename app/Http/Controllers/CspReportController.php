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

        return response()->noContent();
    }
}
