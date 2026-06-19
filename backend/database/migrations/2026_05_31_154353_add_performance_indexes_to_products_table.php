<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index('brand_id');
            $table->index('sub_type_id');
            $table->index('main_category_id');
            $table->index('grade_id');
            $table->index('sale_price_after_discount');
            $table->index('stock');
            $table->index('market_stock');
            $table->index(['brand_id', 'stock']);
            $table->index(['sub_type_id', 'stock']);
            $table->index(['main_category_id', 'stock']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['brand_id']);
            $table->dropIndex(['sub_type_id']);
            $table->dropIndex(['main_category_id']);
            $table->dropIndex(['grade_id']);
            $table->dropIndex(['sale_price_after_discount']);
            $table->dropIndex(['stock']);
            $table->dropIndex(['market_stock']);
            $table->dropIndex(['brand_id', 'stock']);
            $table->dropIndex(['sub_type_id', 'stock']);
            $table->dropIndex(['main_category_id', 'stock']);
        });
    }
};
