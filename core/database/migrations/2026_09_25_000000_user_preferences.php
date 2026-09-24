<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1m — wave 4D: the dashboard preferences one operator holds, and the ONLY place they may live.
 *
 * ── Why a table and not a column ─────────────────────────────────────────────────────────────
 *
 * The obvious home for "which language does this person want the dashboard in" is a column on
 * `users`. It cannot go there, and the reason is the same one M1g gave for `core_user_roles`, only
 * sharper: `users` is a LEGACY table that core is forbidden to write (AGENTS §3), the `legacy`
 * connection is held in `tx_read_only = 1` so the SERVER would refuse the UPDATE, and the legacy
 * application still serialises that row into the auth JSON the live storefront consumes.
 *
 * So the preference lives in a core-owned table, keyed by the user's id — the same shape, and for
 * the same reasons, as the grants table beside it.
 *
 * ── What belongs here, and what does not ─────────────────────────────────────────────────────
 *
 * Only what the DASHBOARD needs and the storefront must not see. A locale is that: it changes the
 * language of `/manage` and nothing else — a customer's own language is negotiated per request by
 * the storefront and has no business being decided by whoever last used the admin panel.
 *
 * Deliberately NOT here: anything that duplicates a `users` column. A name or an e-mail copied into
 * core would be a second truth that drifts from the first, and the first is the one the customer's
 * order confirmation is addressed to.
 *
 * ── Rebuild safety ──────────────────────────────────────────────────────────────────────────
 *
 * A human typed every row, and no transform can regenerate one, so this table is DASHBOARD-owned:
 * it joins `CoreChecksumCommand::DASHBOARD_TABLES` and never `CLEAN_TABLES`. Losing it on switch
 * night would be small — everyone's dashboard reverts to Arabic — but it would be loss, and §2.20
 * draws the line at "authored in the dashboard", not at "important".
 *
 * `user_id` carries a CASCADE key into `users` for the same reason `core_user_roles` does: a legacy
 * admin deleting an account must not be blocked by a core table, and a preference belonging to a
 * deleted account is worthless. `storefronts` gets no key here because nothing here names one.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * GUARDED, because this table is never dropped.
         *
         * Switch night runs `migrate` against a database where the preserved tables are still
         * there (§3.4 step 3b drops only `CLEAN_TABLES`), so a bare `Schema::create` on one of
         * them dies at "table already exists" and takes the migrate step with it. Wave 4C's
         * closing rebuild died exactly there, on `storefront_payment_providers`, and
         * `RebuildSurvivesTest` has held the invariant since — it caught this file too, before
         * the rebuild could.
         */
        Schema::hasTable('core_user_preferences') or Schema::create('core_user_preferences', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            // Two today. A value outside the list is refused by the WRITER, not by the column: an
            // enum here would answer a bad value with a 1265 from the driver instead of a sentence.
            $table->string('locale', 8)->default('ar');
            $table->timestamps();

            /*
             * NAMED, like every other foreign key in this schema (🔵, 2026-09-17).
             *
             * An unnamed key gets MariaDB's generated identifier, which is positional and differs
             * between a fresh build and a rebuilt one — so a later migration that wants to drop it
             * has nothing stable to name, and `core:drop-clean` + `migrate` can leave two databases
             * that are identical in content and different in constraint names. The convention is
             * `<table-abbrev>_<column>_fk`, as used by the payment and activity tables.
             *
             * HONEST LIMIT: this table is in `DASHBOARD_TABLES`, so `core:drop-clean` preserves it
             * and this `create` never re-runs on an existing database. Databases built before today
             * keep MariaDB's generated name until the table is dropped for some other reason. That
             * is acceptable because nothing drops this key today — the rule is here so the NEXT
             * migration that touches it has a name to use, and so a fresh install is right.
             */
            $table->foreign('user_id', 'cup_user_fk')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_user_preferences');
    }
};
