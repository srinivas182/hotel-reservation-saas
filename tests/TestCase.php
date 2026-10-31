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
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
