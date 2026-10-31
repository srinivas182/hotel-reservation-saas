<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Demo data is only ever created in the local environment.
     */
    public function run(): void
    {
        if (app()->environment('local')) {
            $this->call(LocalDemoSeeder::class);
        }
    }
}
