<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Restock-notification bookkeeping. `notified_at` lives on wishlist_items
     * (the per-product entry) — set when a restock email is queued, and reset
     * to null when the product drops out of stock again so the next restock
     * notifies once more.
     */
    public function up(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            if (!Schema::hasColumn('wishlist_items', 'notified_at')) {
                $table->timestamp('notified_at')->nullable()->after('offer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('wishlist_items', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
