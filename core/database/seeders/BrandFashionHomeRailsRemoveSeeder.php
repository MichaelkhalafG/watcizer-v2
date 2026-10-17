<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Undo {@see BrandFashionHomeRailsSeeder}: deletes exactly the rails it would create on the `brandfashion`
 * storefront (matched by kind + target, or the custom rails' English titles) and nothing else — the
 * production `offers` rail of 2026-10-14 stays. `php artisan db:seed --class=BrandFashionHomeRailsRemoveSeeder`.
 */
final class BrandFashionHomeRailsRemoveSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BrandFashionHomeRailsSeeder::class, false, ['mode' => 'remove']);
    }
}
