<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1o — the activity log: who changed what, and what it was before (wave 4D).
 *
 * ── Why this exists now and not later ───────────────────────────────────────────────────────
 *
 * The new dashboard is the first one with ROLES and several named people working the same
 * catalogue. That is exactly the moment "who changed this price?" starts being asked, and the
 * moment an answer stops being reconstructible: the old dashboard had one shared login, so the
 * question never had an answer worth recording and nobody missed it.
 *
 * The stock ledger already answers it for UNITS — `inventory_movements` carries `actor_type` and
 * `actor_id` for every movement. Nothing answers it for a price, a title, a category, a promotion
 * or a payment credential, and those are the edits the team makes all day.
 *
 * ── What is stored, and deliberately what is not ────────────────────────────────────────────
 *
 * One row per WRITE, carrying the actor, the subject (`catalog_products`, id 512), the action, and
 * the CHANGED FIELDS ONLY as `{field: {from, to}}`.
 *
 *  • **Not reads.** A log of who looked at what is a different product with a different cost, and
 *    it would bury the writes under thousands of rows a day.
 *  • **Not whole payloads.** A product save carries forty fields and changes two. Storing the
 *    payload makes the table enormous and the answer harder to see — the diff IS the answer.
 *  • **Never a credential value.** `ActivityLog::REDACTED_FIELDS` replaces the value on both sides
 *    with a marker: the row still records THAT the Paymob secret changed, and who changed it,
 *    which is the useful half. A log that quietly copies secrets into a second, less-guarded table
 *    is a way of leaking them, not a way of auditing them.
 *
 * ── Rebuild safety ──────────────────────────────────────────────────────────────────────────
 *
 * DASHBOARD-owned: it joins `CoreChecksumCommand::DASHBOARD_TABLES` and never `CLEAN_TABLES`. It is
 * a record of what humans did, no transform can regenerate a line of it, and switch night's
 * drop-and-rebuild would erase the history of the very migration it is part of. Guarded
 * `Schema::create` for the same reason every table on that list has one.
 *
 * ── The FK, and why only one ────────────────────────────────────────────────────────────────
 *
 * `user_id` cascades into `users` like `core_user_roles` does — a legacy admin deleting an account
 * must not be blocked by a core table. The SUBJECT is deliberately NOT a foreign key: it names
 * rows in a dozen tables, including legacy ones, and a log entry must outlive the thing it
 * describes. Deleting a promotion should not delete the record that somebody deleted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::hasTable('core_activity_log') or Schema::create('core_activity_log', function (Blueprint $table): void {
            $table->id();

            // WHO. Nullable because a CLI run (an importer, a scheduled job) has no user, and
            // "nobody was logged in" is a true and useful answer rather than a missing row.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 191)->nullable();

            // WHAT, as table + id. A string rather than a morph map: the subjects span core and
            // legacy tables and the table name is what a reader recognises.
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id')->nullable();
            // A human label captured AT THE TIME — the product's title, the rule's name. Without it
            // a row about a deleted product reads "catalog_products 512" and means nothing.
            $table->string('subject_label', 191)->nullable();

            // `created`, `updated`, `deleted`, `restored`, `adjusted`, `granted`, `revoked`.
            $table->string('action', 32);

            // The storefront this happened in, where the subject has one. Scoped grants read it.
            $table->unsignedBigInteger('storefront_id')->nullable();

            // {field: {from, to}} for changed fields only. Null for an action with no diff.
            $table->json('changes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // The three questions the screen asks: what happened lately, what did this person do,
            // and what happened to this record.
            $table->index('created_at', 'activity_created_idx');
            $table->index(['user_id', 'created_at'], 'activity_user_idx');
            $table->index(['subject_type', 'subject_id'], 'activity_subject_idx');

            $table->foreign('user_id', 'activity_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_activity_log');
    }
};
