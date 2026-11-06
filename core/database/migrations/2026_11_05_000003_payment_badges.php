<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The picture of each payment method (the twelve updates, Part 7, 2026-11-05).
 *
 * The badges in the footer, on the cart and at checkout are DERIVED from what the storefront can
 * actually take: cash on delivery, plus every enabled method of an enabled, complete provider contract
 * (`CheckoutMethods` — the list the checkout itself offers). This table holds only how each one LOOKS:
 *
 *   storefront_payment_badges   (storefront_id, method) → image_path
 *
 * keyed by the METHOD key (`cod`, `card`, `wallet`, `valu`, …), not by a payment-method row, because
 * cash on delivery has no row in `storefront_payment_methods` and still needs a picture. A row here
 * can never make a badge appear: a picture uploaded for a method the storefront does not offer is
 * stored and not shown. A method with no picture shows its name.
 *
 * Authored in the dashboard → in `CoreChecksumCommand::DASHBOARD_TABLES`, creation `hasTable`-guarded.
 *
 * Rollback, by hand (the ledger is `core_migrations`):
 *
 *   DROP TABLE storefront_payment_badges;
 *   DELETE FROM core_migrations WHERE migration = '2026_11_05_000003_payment_badges';
 *
 * The uploaded files stay under Uploads_Images/Payment; nothing else references them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('storefront_payment_badges') or Schema::create('storefront_payment_badges', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('storefront_id');
            $t->string('method', 32);
            $t->string('image_path', 255);
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();

            $t->unique(['storefront_id', 'method'], 'spb_method_unique');
            // with its storefront, as every storefront-owned table goes
            $t->foreign('storefront_id', 'spb_storefront_fk')->references('id')->on('storefronts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_payment_badges');
    }
};
