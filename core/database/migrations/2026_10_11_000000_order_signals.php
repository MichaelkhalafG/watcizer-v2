<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1x (B2, 2026-09-29): what the shopper's BROWSER looked like when the order was placed.
 *
 * Meta's Conversions API requires the client's user agent on a website event, and uses the IP and
 * the `_fbp`/`_fbc` browser ids to match the event to a person. The card `Purchase` is sent from the
 * payment CALLBACK — a server-to-server call from Paymob, with no shopper browser in it — so these
 * have to be captured at `add_order`, the last request the shopper's browser made. One row per order.
 *
 * On the never-dropped list (`CoreChecksumCommand::DASHBOARD_TABLES`): it describes ORDERS, which a
 * rebuild never touches, and has no legacy source. `hasTable` guard for switch night's `migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_order_signals') or Schema::create('core_order_signals', function (Blueprint $t): void {
            $t->unsignedBigInteger('order_id')->primary();
            $t->string('ip', 45)->nullable();          // IPv6 at most
            $t->string('user_agent', 512)->nullable();
            $t->string('fbp', 255)->nullable();        // Meta's `_fbp` browser id, when the storefront sends it
            $t->string('fbc', 255)->nullable();        // Meta's `_fbc` click id, when the storefront sends it
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_order_signals');
    }
};
