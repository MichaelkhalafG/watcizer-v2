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
        $this->call(DemoVariantsSeeder::class, false, ['mode' => 'remove']);
    }
}
