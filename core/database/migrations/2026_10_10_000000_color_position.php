<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ORDER of a product's colours within a role (2026-09-27).
 *
 * A two-tone watch is one product with two colours in one role — "silver with blue", strap
 * Gold + Silver — and `catalog_product_color` has always been able to hold that: its key is
 * (product, colour, role). What it could not say is which colour comes FIRST, so every screen fell
 * back to colour-id order and the dashboard offered one colour per role. `position` is that order:
 * 0 is the primary colour, the one named first ("Silver & Blue") and drawn on the left of the
 * swatch.
 *
 * Additive and defaulted: every existing row becomes position 0, and every reader orders by
 * (position, color_id) — so for the data that exists today nothing reorders anywhere, the compat
 * payloads included. A colour only moves when somebody puts it first in the dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('catalog_product_color', 'position')) {
            Schema::table('catalog_product_color', function (Blueprint $table): void {
                $table->unsignedTinyInteger('position')->default(0)->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('catalog_product_color', 'position')) {
            Schema::table('catalog_product_color', function (Blueprint $table): void {
                $table->dropColumn('position');
            });
        }
    }
};
