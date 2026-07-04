<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fix the schema drift that blocked checkout. The order engine in
 * OrderController already writes guest_name/guest_email/guest_token, allows a
 * null user_id for guests, and stores payment_method values 'paymob'/'whatsapp'
 * — but the live tables never had the matching columns/types, so every insert
 * failed. This only ADDS columns and WIDENS/relaxes types; no data is dropped
 * and the existing order logic is untouched. Raw MODIFY statements are used
 * (instead of ->change()) so doctrine/dbal is not required.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ORDERS ───────────────────────────────────────────────────────────
        // user_id → nullable (guest orders). Drop FK, modify, re-add.
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign('orders_user_id_foreign');
        });
        DB::statement('ALTER TABLE `orders` MODIFY `user_id` BIGINT UNSIGNED NULL');
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });

        // payment_method enum('cash','card') → varchar so cash/card/paymob/whatsapp all fit.
        DB::statement("ALTER TABLE `orders` MODIFY `payment_method` VARCHAR(20) NOT NULL DEFAULT 'cash'");

        // total_price_for_order decimal(8,2) caps at 999,999.99 → widen to decimal(12,2).
        DB::statement('ALTER TABLE `orders` MODIFY `total_price_for_order` DECIMAL(12,2) NOT NULL');

        // Guest columns the order logic already writes.
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'guest_name')) {
                $table->string('guest_name')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('orders', 'guest_email')) {
                $table->string('guest_email')->nullable()->after('guest_name');
            }
            if (!Schema::hasColumn('orders', 'guest_token')) {
                $table->string('guest_token')->nullable()->after('guest_email');
            }
            if (!Schema::hasColumn('orders', 'guest_phone')) {
                $table->string('guest_phone')->nullable()->after('guest_token');
            }
        });

        // ── ORDER ITEMS ──────────────────────────────────────────────────────
        // Same decimal(8,2) cap on the line prices.
        DB::statement('ALTER TABLE `order_items` MODIFY `piece_price` DECIMAL(12,2) NOT NULL');
        DB::statement('ALTER TABLE `order_items` MODIFY `total_price` DECIMAL(12,2) NOT NULL');

        // ── ADDRESSES ────────────────────────────────────────────────────────
        // user_id → nullable (guests own addresses with no user), + guest_token
        // so an inline guest address can be tied back to its cart session.
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropForeign('addresses_user_id_foreign');
        });
        DB::statement('ALTER TABLE `addresses` MODIFY `user_id` BIGINT UNSIGNED NULL');
        Schema::table('addresses', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
            if (!Schema::hasColumn('addresses', 'guest_token')) {
                $table->string('guest_token')->nullable()->after('user_id');
            }
        });
    }

    public function down(): void
    {
        // ── ADDRESSES ──
        Schema::table('addresses', function (Blueprint $table) {
            if (Schema::hasColumn('addresses', 'guest_token')) {
                $table->dropColumn('guest_token');
            }
            $table->dropForeign(['user_id']);
        });
        DB::statement('ALTER TABLE `addresses` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');
        Schema::table('addresses', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });

        // ── ORDER ITEMS ──
        DB::statement('ALTER TABLE `order_items` MODIFY `piece_price` DECIMAL(8,2) NOT NULL');
        DB::statement('ALTER TABLE `order_items` MODIFY `total_price` DECIMAL(8,2) NOT NULL');

        // ── ORDERS ──
        Schema::table('orders', function (Blueprint $table) {
            foreach (['guest_name', 'guest_email', 'guest_token', 'guest_phone'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        DB::statement('ALTER TABLE `orders` MODIFY `total_price_for_order` DECIMAL(8,2) NOT NULL');
        DB::statement("ALTER TABLE `orders` MODIFY `payment_method` ENUM('cash','card') NOT NULL");

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        DB::statement('ALTER TABLE `orders` MODIFY `user_id` BIGINT UNSIGNED NOT NULL');
        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });
    }
};
