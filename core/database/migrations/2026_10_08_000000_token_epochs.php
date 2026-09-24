<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1v — per-customer token epoch, i.e. "log out everywhere" (Phase 1, piece 4b, 2026-09-22).
 *
 * ── The gap this closes, and why the developer overturned the note that pinned it ───────────
 *
 * Piece 4 shipped password reset and recorded a consequence rather than hiding it: a reset did NOT
 * invalidate tokens the customer already held, because core has no remember-me cookie to cycle and
 * no per-user epoch. Developer decision, 2026-09-22:
 *
 *   > *"A password reset — and a password change while signed in — must invalidate every token the
 *   > customer already holds. The reset flow exists for exactly the case where someone else has the
 *   > account; leaving the thief's token valid for thirty days defeats it."*
 *
 * That is the correct reading. A reset is the one action whose entire purpose is to take an account
 * back, and a mechanism that leaves the attacker signed in for a month is not a recovery, it is a
 * change of password.
 *
 * ── Why a TABLE, and specifically not a column on `users` ───────────────────────────────────
 *
 * `users` is a legacy table and AGENTS §3 forbids altering one — the prohibition is about the
 * SCHEMA, and it is not softened by §2.18's permission to write existing columns. The legacy
 * application also still `select *`s this table, so a new column would appear in its responses.
 *
 * So the epoch is core-owned, one row per customer, written only when something invalidates.
 * Absent row = never invalidated = every token valid, which is the right default and means this
 * table holds a handful of rows rather than one per account.
 *
 * ── How it is CHECKED, and the one-second window ────────────────────────────────────────────
 *
 * `LegacyJwt::subject()` compares the token's `iat` against `not_before`: issued before the epoch,
 * refused. `iat` is whole seconds, so a token minted in the SAME second as the epoch survives it.
 * That is deliberate rather than overlooked — the comparison has to be strict, or the replacement
 * token issued microseconds after a password change would be refused along with the old ones. The
 * exposure is one second against an attacker who has been holding a token for hours.
 *
 * ── NEVER DROPPED ───────────────────────────────────────────────────────────────────────────
 *
 * Dropping this table would silently re-validate every token a customer had invalidated — the exact
 * failure `core_revoked_tokens` is on the list for, one level wider. `hasTable`-guarded, and in
 * `CoreChecksumCommand::DASHBOARD_TABLES` so `RebuildSurvivesTest` enforces the guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_user_token_epochs') or Schema::create('core_user_token_epochs', function (Blueprint $t): void {
            /*
             * The customer. Primary key rather than an id column: there is exactly one epoch per
             * account and an upsert on it is the whole write path. Not a foreign key into `users`,
             * for the reason M1t gives — a FK from a core table into the shared legacy one makes
             * `core:drop-clean` order-dependent for no gain.
             */
            $t->unsignedBigInteger('user_id')->primary();

            /*
             * Tokens issued STRICTLY BEFORE this instant are refused.
             *
             * `dateTime` rather than `timestamp`, for both reasons M1t records: MariaDB's implicit
             * defaults depend on a column's ordinal position, and `TIMESTAMP` converts through the
             * session time zone on every read — which, for a value compared against a Unix `iat`,
             * is how an epoch would land an hour out.
             */
            $t->dateTime('not_before');

            /*
             * WHAT invalidated the sessions — `customer.reset`, `customer.password`. The audit
             * question after an account is taken back is "did the recovery actually happen", and a
             * bare timestamp cannot answer which action caused it.
             */
            $t->string('reason', 32);

            $t->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_user_token_epochs');
    }
};
