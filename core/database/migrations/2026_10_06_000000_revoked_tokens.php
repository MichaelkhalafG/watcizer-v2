<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1t — revoked customer tokens (storefront Phase 1, piece 2, 2026-09-21).
 *
 * ── The gap this closes, which existed and was flagged ──────────────────────────────────────
 *
 * Core has VERIFIED the legacy JWT since wave 3 and has never issued one. `App\Support\LegacyJwt`
 * says so in its own docblock, and names the hole this table fills:
 *
 *   > the legacy **blacklist** (a token invalidated by `logout`) lives in the legacy app's file
 *   > cache, which core cannot read. Until auth moves to core or the two share a cache, a token
 *   > invalidated on the legacy host stays valid here until it expires.
 *
 * Phase 1 is "auth moves to core". Core now issues the tokens, so core owns their revocation, and
 * without somewhere to record it **`logout` would be a no-op that returns 200** — a signed-out
 * customer whose token keeps working for up to thirty days. That is a security regression against
 * the behaviour being replaced, not a missing nicety.
 *
 * ── Why a TABLE and not the cache ───────────────────────────────────────────────────────────
 *
 * The legacy app used tymon's blacklist in its file cache. Core will not, for one reason that
 * settles it: `php artisan cache:clear` is a routine deploy step in this project's own runbook, and
 * it would silently UN-REVOKE every token anybody had signed out of. A revocation that a cache
 * flush undoes is not a revocation. The rows are tiny, bounded by the token TTL, and pruned.
 *
 * ── NEVER DROPPED, so it joins the preserved list ───────────────────────────────────────────
 *
 * Same argument one level further on: switch night's drop-and-rebuild exists to throw away
 * transform OUTPUT, and a revocation has no legacy source to rebuild from. Dropping it would sign
 * every signed-out customer back in — quietly, at the worst possible moment. So the table is in
 * `CoreChecksumCommand::DASHBOARD_TABLES` (the never-dropped list; the name says "authored in the
 * dashboard" but the LINE it draws is "no transform can regenerate this", which is why
 * `payment_reconciliation_findings` and `core_activity_log` are already on it), and its
 * `Schema::create` is `hasTable`-guarded so the rebuild's `migrate` step does not die at
 * "table already exists" — the failure wave 4C's closing rebuild hit on
 * `storefront_payment_providers`. `RebuildSurvivesTest` holds both halves.
 *
 * ── Shape ───────────────────────────────────────────────────────────────────────────────────
 *
 * The `jti` IS the key: it is what the token carries, it is unique per token, and making it the
 * primary key means a double logout is an idempotent upsert rather than a duplicate row.
 * `expires_at` is the token's own `exp`, which is what makes pruning possible — a row is useless
 * the moment the token it revokes would have expired anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_revoked_tokens') or Schema::create('core_revoked_tokens', function (Blueprint $t): void {
            /*
             * tymon's `jti` is `Str::random()` — 16 characters — and core mints the same shape.
             * 64 leaves room for a longer identifier later without a migration, and is short enough
             * to be a primary key on `utf8mb4` without approaching MySQL's key-length limit.
             */
            $t->string('jti', 64)->primary();

            /*
             * Whose token it was. Not a foreign key: `users` is the shared legacy table and a FK
             * from a core table into it would make `core:drop-clean` order-dependent for no gain —
             * the column is for answering "did this person sign out", never for joining.
             */
            $t->unsignedBigInteger('user_id');

            /*
             * `dateTime`, not `timestamp`, and both for the same two reasons.
             *
             * (1) MariaDB gives the FIRST `TIMESTAMP NOT NULL` column in a table an implicit
             *     `CURRENT_TIMESTAMP` default and the SECOND one `'0000-00-00 00:00:00'`, which
             *     under this application's strict mode is rejected outright — measured here, on
             *     10.4: *"Invalid default value for 'revoked_at'"*. A column whose correctness
             *     depends on its ordinal position in the table is a trap for whoever adds a third.
             * (2) `TIMESTAMP` converts to and from the session time zone on every read and write.
             *     These two values are compared against `Carbon::now()` in UTC and against a `exp`
             *     claim that is a Unix timestamp; a silent conversion in the middle of that is how
             *     a revocation would expire an hour early or late.
             */

            // The token's own `exp`, so a pruner can delete what can no longer be presented.
            $t->dateTime('expires_at');

            // When the revocation happened — the audit half, and distinct from `expires_at`.
            $t->dateTime('revoked_at');

            // Explicit name, ≤ 63 characters (AGENTS §2.14): the pruner's only query.
            $t->index('expires_at', 'crt_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_revoked_tokens');
    }
};
