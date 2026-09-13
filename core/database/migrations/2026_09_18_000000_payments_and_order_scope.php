<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1h — the payments seam of study §3.9, plus the two columns that design assumes exist.
 *
 * ── The four things this migration does ──────────────────────────────────────────────────────
 *
 *  1. **`storefront_payment_providers`** — one row per CONTRACT (credentials, one HMAC secret, one
 *     callback). **`storefront_payment_methods`** — one row per thing a customer can PICK, under
 *     its provider, carrying that provider's `integration_id` (not a secret). Plus the
 *     translations table on the astrotomic pattern, because a label is the merchant's words in
 *     each locale (AGENTS §2.19, §2.1 rule 2). valU and Tamara are METHODS inside Paymob, and the
 *     two-level shape is what makes that expressible.
 *
 *  2. **The wave-3 unique index on `payment_statuses` is REPLACED.** `UNIQUE (pay_transaction_id)`
 *     is wrong the moment a second provider goes live: transaction ids are unique only WITHIN a
 *     provider, so a Fawry id colliding with a Paymob one would be rejected as a replay and a real
 *     payment would be silently lost. It becomes `UNIQUE (provider, pay_transaction_id)`, and
 *     `provider` is **NOT NULL DEFAULT 'paymob'** — nullable would exempt every row the legacy app
 *     writes (it sets no provider) from the constraint, because MariaDB treats NULLs as distinct,
 *     and the idempotency guard would vanish for exactly the rows still arriving on the old path.
 *
 *  3. **`orders.storefront_id`** (nullable, indexed, no FK). §3.9.2's ownership check is written as
 *     "assert `orders.storefront_id` equals the route's storefront" — and the column did not exist:
 *     wave 3 recorded the storefront only in `inventory_movements`, which legacy-written orders do
 *     not have. Without it a callback cannot be scoped to a storefront at all, which is the whole
 *     point of the per-storefront callback. Additive and FK-less for the same reason wave 3.5's
 *     `variant_id` is (see `2026_09_16_000000_variant_stock`): `orders` is a SHARED commerce table
 *     the legacy app still writes, and a constraint added to it is a constraint that app must
 *     satisfy. NULL means "written before core recorded it" — see `PaymentCallbackController` for
 *     how the ownership check reads NULL, and it is a deviation with a sunset, not a permanent one.
 *
 *  4. **`orders.paid_via_provider` / `paid_via_method`** — the denormalised answer to "who took
 *     this money", written by the attempt that succeeds (§3.9.6). Plain strings on purpose: they
 *     survive the merchant deleting a method row, which the FK on
 *     `payment_statuses.storefront_payment_method_id` deliberately does not.
 *
 *     **Corrected 2026-09-13:** this used to say "written once … and never updated", and the
 *     callback writes it with a plain `update()`, so a second success WOULD overwrite it. Nothing
 *     in the schema enforces once-ness. What actually prevents a second success from landing on an
 *     order that has moved on is `App\Domain\Payment\CallbackPolicy`; the column is not the
 *     guard, and a comment claiming it was is worse than no comment — it invites the next reader to
 *     rely on it.
 *
 * ── What this migration does NOT do ──────────────────────────────────────────────────────────
 *
 * It inserts NO credentials. Migrating Watchizer's three `PAYMOB_*` values out of `.env` and into
 * `storefront_payment_providers` is a switch-night runbook step at T-14 d (§3.9.8) performed by the
 * developer, because those values must not pass through this repository or an agent's hands
 * (AGENTS §3).
 *
 * ── Every `create` here is GUARDED, because these tables survive a rebuild ───────────────────
 *
 * The three payment tables are dashboard-authored and therefore on `CoreChecksumCommand`'s
 * never-dropped list: `core:drop-clean` leaves them standing while clearing every `core_migrations`
 * row, so the `migrate` that follows runs this migration again against tables that still exist.
 * `storefronts` and `storefront_banners` guard their `create` the same way for the same reason.
 * Without the guard every drop-and-rebuild — which is what switch night IS (§3.4 step 3b) — dies at
 * "table already exists", half-applied. Measured, not theorised: wave 4C's own closing rebuild.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('storefront_payment_providers') or Schema::create('storefront_payment_providers', function (Blueprint $table): void {
            $table->id();
            /*
             * Every constraint is NAMED, and short. Laravel's generated name for the methods table
             * would have been `storefront_payment_methods_storefront_payment_provider_id_foreign`
             * — 65 characters, and MariaDB refuses an identifier over 63 (§2.14). The first run of
             * this migration died on exactly that, half-created.
             */
            $table->unsignedBigInteger('storefront_id');
            $table->foreign('storefront_id', 'spp_storefront_fk')
                ->references('id')->on('storefronts')->cascadeOnDelete();
            // An application constant, never an enum (§2.1-6): paymob, fawry, cod, whatsapp, …
            $table->string('provider', 32);
            $table->boolean('is_enabled')->default(false);
            /*
             * The contract's secret material, Laravel `encrypted` cast (AES-256-CBC under APP_KEY).
             * NULL for `cod` / `whatsapp`, which are providers with no credentials on purpose so
             * that cash and WhatsApp get the same enable/label/icon/position machinery as a card.
             *
             * longtext because ciphertext is ~1.4× the plaintext plus a MAC, and never indexed or
             * queried by value — an encrypted column cannot be searched.
             */
            $table->longText('credentials')->nullable();
            // NON-secret contract config the dashboard may display (return URL, reference prefix).
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['storefront_id', 'provider'], 'spp_storefront_provider_unique');
            $table->index(['storefront_id', 'is_enabled'], 'spp_enabled_idx');
        });

        Schema::hasTable('storefront_payment_methods') or Schema::create('storefront_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('storefront_payment_provider_id');
            $table->foreign('storefront_payment_provider_id', 'spm_provider_fk')
                ->references('id')->on('storefront_payment_providers')->cascadeOnDelete();
            $table->string('method', 32);
            // The provider's own id for THIS method. NOT a secret: Paymob puts it in the signed
            // callback payload, and the dashboard shows it normally.
            $table->string('integration_id', 64)->nullable();
            // An asset KEY the frontend resolves (`visa`, `valu`), never a URL and never an upload.
            $table->string('icon', 64)->nullable();
            $table->boolean('is_enabled')->default(false);
            // STOREFRONT-WIDE display order, across providers: the merchant orders methods, not
            // contracts (§3.9.5). Lowest sort wins a duplicated method key.
            $table->unsignedSmallInteger('sort')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();

            // Per PROVIDER, not per storefront: `card` via Paymob AND `card` via Fawry is the
            // arrangement the business is heading for, and the collapse happens at display time.
            $table->unique(['storefront_payment_provider_id', 'method'], 'spm_provider_method_unique');
            $table->index(['storefront_payment_provider_id', 'is_enabled', 'sort'], 'spm_enabled_sort_idx');
        });

        Schema::hasTable('storefront_payment_method_translations') or Schema::create('storefront_payment_method_translations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('storefront_payment_method_id');
            $table->foreign('storefront_payment_method_id', 'spmt_method_fk')
                ->references('id')->on('storefront_payment_methods')->cascadeOnDelete();
            $table->char('locale', 2);
            $table->string('label');

            $table->unique(['storefront_payment_method_id', 'locale'], 'spmt_method_locale_unique');
            $table->index('locale', 'spmt_locale_idx');
        });

        // ── the payment_statuses correction ──────────────────────────────────────────────────
        if (! Schema::hasColumn('payment_statuses', 'provider')) {
            Schema::table('payment_statuses', function (Blueprint $table): void {
                $table->string('provider', 32)->default('paymob')->after('order_id');
                $table->string('method', 32)->nullable()->after('provider');
                $table->unsignedBigInteger('storefront_payment_method_id')->nullable()->after('method');

                $table->foreign('storefront_payment_method_id', 'ps_method_fk')
                    ->references('id')->on('storefront_payment_methods')->nullOnDelete();
                $table->index('provider', 'ps_provider_idx');
            });
        }

        // Drop the wave-3 index and create the provider-scoped one. Both names are checked first:
        // a rehearsal database may have been built at any point in the sequence.
        $indexes = self::indexNames('payment_statuses');
        if (in_array('ps_pay_transaction_unique', $indexes, true)) {
            DB::statement('ALTER TABLE `payment_statuses` DROP INDEX `ps_pay_transaction_unique`');
        }
        if (! in_array('ps_provider_transaction_unique', self::indexNames('payment_statuses'), true)) {
            DB::statement(
                'ALTER TABLE `payment_statuses` ADD UNIQUE `ps_provider_transaction_unique` (`provider`, `pay_transaction_id`)'
            );
        }

        // ── the two columns §3.9 assumes on orders ───────────────────────────────────────────
        if (! Schema::hasColumn('orders', 'storefront_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->unsignedBigInteger('storefront_id')->nullable()->after('id');
                $table->index('storefront_id', 'orders_storefront_idx');
            });
        }
        if (! Schema::hasColumn('orders', 'paid_via_provider')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('paid_via_provider', 32)->nullable()->after('payment_method');
                $table->string('paid_via_method', 32)->nullable()->after('paid_via_provider');
            });
        }
    }

    public function down(): void
    {
        // The additive columns on the SHARED tables are left in place deliberately: dropping a
        // column the legacy app may by then be reading is a bigger risk than an unused column, and
        // `down()` on a shared table is not a path any runbook takes.
        Schema::dropIfExists('storefront_payment_method_translations');
        Schema::dropIfExists('storefront_payment_methods');

        if (Schema::hasColumn('payment_statuses', 'storefront_payment_method_id')) {
            Schema::table('payment_statuses', function (Blueprint $table): void {
                $table->dropForeign('ps_method_fk');
            });
        }

        Schema::dropIfExists('storefront_payment_providers');
    }

    /**
     * The index names on a table, so this migration is idempotent against a database built at any
     * point in the M1 sequence.
     *
     * @return list<string>
     */
    private static function indexNames(string $table): array
    {
        $out = [];
        foreach (DB::select('SHOW INDEX FROM `'.$table.'`') as $row) {
            $name = is_object($row) && property_exists($row, 'Key_name') ? $row->Key_name : null;
            if (is_string($name)) {
                $out[$name] = true;
            }
        }

        return array_keys($out);
    }
};
