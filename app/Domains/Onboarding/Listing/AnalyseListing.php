<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Listing;

use App\Domains\Onboarding\Models\ListingImport;
use App\Domains\Tenancy\Contracts\RequiresHotelContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Reads an import in the background: a website and a few photos can take
 * a minute, which must not hold the owner's page (or a weak connection).
 */
final class AnalyseListing implements RequiresHotelContext, ShouldQueue
{
    use Queueable;

    /** Retry policy (S16): one attempt; the owner can start again. */
    public int $tries = 1;

    /** Seconds before a stuck attempt is stopped. */
    public int $timeout = 180;

    public function __construct(public readonly string $importId) {}

    public function handle(ListingImporter $importer): void
    {
        $import = ListingImport::query()->find($this->importId);

        if ($import === null || $import->status !== 'processing') {
            return;
        }

        $importer->analyse($import);
    }

    public function failed(?Throwable $exception): void
    {
        ListingImport::query()->whereKey($this->importId)->where('status', 'processing')->update([
            'status' => 'failed',
            'error' => (string) __('Reading took too long or failed. Please try again.'),
        ]);
    }
}
