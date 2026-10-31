<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Demo data is only ever created in the local environment.
     */
    public function run(): void
    {
        Artisan::call('permissions:sync');

        if (app()->environment('local')) {
            $this->call(LocalDemoSeeder::class);
        }
    }
}
