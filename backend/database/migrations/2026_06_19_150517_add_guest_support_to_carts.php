<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Drop the FK so the user_id column type can be altered.
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign('carts_user_id_foreign');
        });

        // Make user_id nullable (guests have no user_id) without doctrine/dbal.
        DB::statement('ALTER TABLE `carts` MODIFY `user_id` BIGINT UNSIGNED NULL');

        Schema::table('carts', function (Blueprint $table) {
            // Re-add the FK (preserves original behavior; now nullable).
            $table->foreign('user_id')->references('id')->on('users');

            $table->char('guest_token', 36)
                  ->nullable()
                  ->unique()
                  ->after('user_id');
            $table->timestamp('expires_at')
                  ->nullable()
                  ->after('updated_at');
        });

        // Prevent duplicate identical cart lines.
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unique(
                ['cart_id', 'product_id', 'offer_id',
                 'color_band', 'color_dial', 'type_stock'],
                'uniq_cart_line'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropUnique('uniq_cart_line');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['guest_token', 'expires_at']);
        });

        DB::statement('ALTER TABLE `carts` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');

        Schema::table('carts', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
};
