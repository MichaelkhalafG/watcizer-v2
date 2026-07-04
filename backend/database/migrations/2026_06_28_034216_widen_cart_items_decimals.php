<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widen cart_items price columns from decimal(8,2) (caps at 999,999.99) to
 * decimal(12,2) so luxury watches priced above 1,000,000 EGP can be added to
 * the cart. Mirrors the same widening already applied to orders/order_items.
 * Raw MODIFY statements avoid the doctrine/dbal dependency; NOT NULL preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `cart_items` MODIFY `piece_price` DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE `cart_items` MODIFY `total_price` DECIMAL(12,2) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `cart_items` MODIFY `piece_price` DECIMAL(8,2) NOT NULL');
        DB::statement('ALTER TABLE `cart_items` MODIFY `total_price` DECIMAL(8,2) NOT NULL');
    }
};
