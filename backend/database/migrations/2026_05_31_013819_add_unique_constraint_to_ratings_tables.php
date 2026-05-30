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
        Schema::table('product_ratings', function (Blueprint $table) {
            $table->unique(['user_id', 'product_id']);
        });
        Schema::table('offer_ratings', function (Blueprint $table) {
            $table->unique(['user_id', 'offer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_ratings', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'product_id']);
        });
        Schema::table('offer_ratings', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'offer_id']);
        });
    }
};
