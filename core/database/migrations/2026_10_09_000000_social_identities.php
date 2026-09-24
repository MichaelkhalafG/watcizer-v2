<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1w — core-owned social identities (storefront Phase 1, piece 5, 2026-09-22).
 *
 * ── Why a NEW table rather than the legacy `social_accounts` ────────────────────────────────
 *
 * Developer decision, 2026-09-21: *"a new core table rather than legacy `social_accounts`. A
 * customer who linked Google on the legacy host links again on first Google login here."*
 *
 * The consequence is deliberate and it is smaller than it sounds. A link is not a credential and
 * not history: it is a mapping from "this Google account" to "this shop account", and re-creating
 * it costs the customer exactly one extra click, ONCE, invisibly — they press the same Google
 * button, the provider returns the same verified address, and `CustomerSocial` attaches the
 * identity to the account that address already owns. Nobody is asked to do anything.
 *
 * What it buys is that the legacy application keeps its own table untouched while it is still
 * running, which AGENTS §3 requires and which stops two applications writing one mapping table
 * with no coordination between them.
 *
 * ── The unique key is the PROVIDER's identifier, not the e-mail ─────────────────────────────
 *
 * `(provider, provider_id)` is unique because that pair IS the identity. The e-mail is not: people
 * change the address on a Google account, and two providers can report the same address for two
 * different people. Keying on the address would mean a customer who changes their Google e-mail
 * loses their link, and — worse — that a second provider reporting a shared address could resolve
 * to somebody else's shop account.
 *
 * `user_id` is indexed but NOT unique: one shop account may link Google AND Microsoft.
 *
 * ── NEVER DROPPED ───────────────────────────────────────────────────────────────────────────
 *
 * A link has no legacy source to rebuild from once core owns it, and dropping it would silently
 * disconnect every social login — the customer would press the Google button and, if their address
 * still matched, quietly re-link; if it did not, they would be looking at a stranger's empty
 * account creation. `hasTable`-guarded, and in `CoreChecksumCommand::DASHBOARD_TABLES` so
 * `RebuildSurvivesTest` enforces the guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_social_identities') or Schema::create('core_social_identities', function (Blueprint $t): void {
            $t->id();

            // `google`, `microsoft` — the Socialite driver name, which is also what the route
            // segment carries and what `CustomerSocial::PROVIDERS` allows.
            $t->string('provider', 32);

            /*
             * The provider's own identifier for the person. A string, not an integer: Google's
             * `sub` is a 21-digit numeric STRING today and there is no promise it stays numeric or
             * stays that length.
             */
            $t->string('provider_id', 191);

            /*
             * Not a foreign key into `users`, for the reason M1t gives: a FK from a core table into
             * the shared legacy one makes `core:drop-clean` order-dependent for no gain.
             */
            $t->unsignedBigInteger('user_id');

            /*
             * The address the provider reported AT LINK TIME, kept for support rather than for
             * resolution — nothing looks an identity up by it. When a customer says "I cannot get
             * into my account with Google any more", the useful question is which address the link
             * was made with, and this is the only place that answers it.
             */
            $t->string('linked_email', 191)->nullable();

            $t->dateTime('created_at');
            $t->dateTime('updated_at');

            // Explicit names, ≤ 63 characters (AGENTS §2.14).
            $t->unique(['provider', 'provider_id'], 'csi_provider_identity_unq');
            $t->index('user_id', 'csi_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_social_identities');
    }
};
