<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * Feature tests assert on server responses, not compiled assets, so
     * Vite is disabled and CI does not need a frontend build to run them.
     */
    /**
     * Test-only tables (for fixture models) are migrated alongside the app.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app->afterResolving('migrator', function (Migrator $migrator): void {
            $migrator->path(base_path('tests/Fixtures/migrations'));
        });

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Tests never reach real services (Meta, AI providers): every HTTP call must be faked.
        \Illuminate\Support\Facades\Http::preventStrayRequests();

        // Legal texts (S14) are published by a migration; most tests are not
        // about them, so they start without any. Legal tests publish their own.
        if (in_array(\Illuminate\Foundation\Testing\RefreshDatabase::class, class_uses_recursive($this), true)) {
            \Illuminate\Support\Facades\DB::table('legal_documents')->delete();
        }
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
