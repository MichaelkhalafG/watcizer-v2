<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Removes the DEMO variants {@see DemoVariantsSeeder} attached and restores each product's own
 * stock. `php artisan db:seed --class=DemoVariantsRemoveSeeder`, then `inventory:verify`.
 */
final class DemoVariantsRemoveSeeder extends Seeder
{
    public function run(): void
    {
        // The same door, checked here too: this seeder must refuse on its own, not only because the
        // one it calls would.
        DemoVariantsSeeder::refuseUnlessLocal();
        $this->call(DemoVariantsSeeder::class, false, ['mode' => 'remove']);
    }
}
