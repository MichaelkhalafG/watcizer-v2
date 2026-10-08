<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seeds NOTHING on purpose (2026-10-08). It used to call LuxuryWatchSeeder, which DELETES the
        // catalogue tables before reseeding them (LuxuryWatchSeeder.php:188–211) — one careless
        // `php artisan db:seed` against whatever database this .env names would have wiped the legacy
        // catalogue. Run a seeder by name if one is ever needed; do not put a call back here.
        // LuxuryWatchSeeder itself goes with the legacy app (C3).
    }
}
