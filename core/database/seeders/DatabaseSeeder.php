<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Intentionally empty: seeding happens via core:transform and explicit seeders only
     * (e.g. `php artisan db:seed --class=StorefrontSeeder`). The database is shared with
     * the legacy application — legacy tables are never seeded from here.
     */
    public function run(): void
    {
        //
    }
}
