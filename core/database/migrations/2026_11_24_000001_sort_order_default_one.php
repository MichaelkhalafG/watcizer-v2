<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * THE PRODUCT SORT DEFAULT IS 1 (Michael, 2026-11-24). Featured products are 0 and come first; every other product is
 * 1, so the team sets 0 only on what they want first and never has to set every other product to 1 by hand.
 *   storefront_product.sort_order: DEFAULT 0 → DEFAULT 1. Only the default: no row changes here (the existing rows
 *   become 1 through the four-pass data step `catalog:sort-default`).
 * `ALTER COLUMN … SET DEFAULT` changes the table's definition only (no copy, no lock of the rows). Undo: the same with 0
 * (the deploy's undo runs it as SQL: migrate:rollback is refused in every environment of this core).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE storefront_product ALTER COLUMN sort_order SET DEFAULT 1');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE storefront_product ALTER COLUMN sort_order SET DEFAULT 0');
    }
};
