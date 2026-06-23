<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The product price columns were created as DECIMAL(8,2) (max 999,999.99),
 * which cannot hold realistic luxury-watch retail prices in EGP (often well
 * above 1,000,000). Widen them to DECIMAL(12,2). Raw ALTER is used so no
 * doctrine/dbal dependency is required for the column-type change.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `products` MODIFY `purchase_price` DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE `products` MODIFY `selling_price` DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE `products` MODIFY `sale_price_after_discount` DECIMAL(12,2) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `products` MODIFY `purchase_price` DECIMAL(8,2) NOT NULL');
        DB::statement('ALTER TABLE `products` MODIFY `selling_price` DECIMAL(8,2) NOT NULL');
        DB::statement('ALTER TABLE `products` MODIFY `sale_price_after_discount` DECIMAL(8,2) NULL');
    }
};
