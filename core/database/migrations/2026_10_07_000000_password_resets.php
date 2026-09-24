<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1u — core-owned password-reset tokens (storefront Phase 1, piece 4, 2026-09-21).
 *
 * ── Why a NEW table rather than the legacy `password_reset_tokens` ──────────────────────────
 *
 * Developer decision, 2026-09-21: *"a new `core_password_resets` table. The legacy broker still
 * points at the old table, and a reset token is short-lived, so nothing is lost by keeping them
 * apart."* Three things follow from that and each one is a reason on its own:
 *
 *  1. **The legacy application is still running and still resetting passwords.** Two brokers
 *     sharing one table would delete each other's tokens — `DatabaseTokenRepository::create()`
 *     deletes every existing row for the address before inserting — so a customer who asked for a
 *     link on one host would have it silently invalidated by a request on the other.
 *  2. **Nothing is lost by separating them.** A reset token lives sixty minutes. There is no
 *     history here to preserve and nobody to migrate; the worst case at cutover is a customer
 *     whose ten-minute-old link stops working and who asks for another.
 *  3. **AGENTS §3 names this table as a hazard** — *"the `password_reset_tokens` table exists in
 *     the shared schema, so the broker is one route away from a legacy write"*. Phase 1 is that
 *     route arriving. Repointing the broker at a core table is what closes the hazard rather than
 *     walking into it.
 *
 * ── The shape is Laravel's own, deliberately ────────────────────────────────────────────────
 *
 * `Illuminate\Auth\Passwords\DatabaseTokenRepository` reads and writes `email`, `token` and
 * `created_at`, and it HASHES the token before storing it — the value in this table cannot be
 * mailed to anybody, which is the property that makes a leaked database row useless for taking
 * over an account. Core uses that repository rather than hand-rolling token generation, expiry and
 * throttling; what core changes is only WHICH TABLE it points at (`config/auth.php`) and that the
 * password write at the end goes through `App\Domain\Access\UserWrites`.
 *
 * ── Guarded, therefore listed ───────────────────────────────────────────────────────────────
 *
 * `core:drop-clean` clears the WHOLE `core_migrations` ledger, so every core migration re-runs on
 * switch night — against a database where any table outside `CLEAN_TABLES` still exists. A bare
 * `Schema::create` here would die at "table already exists" and take the second command of the
 * runbook with it, which is exactly what happened to wave 4C on `storefront_payment_providers`.
 *
 * So this table joins `CoreChecksumCommand::DASHBOARD_TABLES`. That list's NAME says "authored in
 * the dashboard" and nobody authors a reset token; its FUNCTION is "survives the drop and therefore
 * needs a `hasTable` guard", which `RebuildSurvivesTest` enforces for every name on it. Listing it
 * is about the guard, not about the value of the rows — those are genuinely disposable, and saying
 * so here is better than inventing an importance this table does not have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_password_resets') or Schema::create('core_password_resets', function (Blueprint $t): void {
            // One outstanding token per address, which is the repository's own model: creating a
            // new one deletes the old, so a second "forgot password" click invalidates the first
            // link rather than leaving two live.
            $t->string('email', 191)->primary();

            // The HASH of the token, never the token. `DatabaseTokenRepository` hashes on write and
            // verifies with `Hash::check`, so this column cannot be turned back into a link.
            $t->string('token', 255);

            // Nullable to match Laravel's own stub and the legacy table it replaces; the repository
            // always writes it, and reads it to decide expiry.
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_password_resets');
    }
};
