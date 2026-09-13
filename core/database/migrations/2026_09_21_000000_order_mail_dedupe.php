<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1k — `integration_outbox.dedupe_key`, so an order e-mail cannot be sent twice.
 *
 * ── Why the outbox needed one more column ────────────────────────────────────────────────────
 *
 * Wave 3 used this table to RECORD the mail core could not yet send. Prerequisite (a) turns it
 * into the thing that actually sends it, and that changes what the table has to guarantee: not
 * "we noted it" but "exactly one message per event, ever". Two operators clicking *Advance* on
 * the same order, a shopper double-submitting a checkout, or a one-minute cron tick overlapping
 * an in-request send are all ordinary, and all of them would otherwise mail the customer twice.
 *
 * The guard is the DATABASE's, not the application's: a UNIQUE key on a string the enqueue side
 * composes from the event and the recipient (`order.placed:customer:1234`,
 * `order.status.shipped:1234`). The second insert is refused by MariaDB, so the race has no
 * window at all — the same reasoning as `payment_statuses.pay_transaction_id` (M1e) and the
 * ledger's exactly-once index (wave 3).
 *
 * NULL is allowed and repeats freely, because MySQL's unique indexes ignore NULLs: the `morabaa`
 * channel's stock rows carry none and are unaffected by this migration.
 *
 * 191 characters, not 255: this database's `utf8mb4` + InnoDB combination caps a single-column
 * index key at 191 characters on the older row formats, and a dedupe key is a composed
 * identifier, never free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('integration_outbox', 'dedupe_key')) {
            return;
        }

        Schema::table('integration_outbox', function (Blueprint $t): void {
            $t->string('dedupe_key', 191)->nullable()->after('event');
            $t->unique('dedupe_key', 'io_dedupe_uq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('integration_outbox', 'dedupe_key')) {
            return;
        }

        Schema::table('integration_outbox', function (Blueprint $t): void {
            $t->dropUnique('io_dedupe_uq');
            $t->dropColumn('dedupe_key');
        });
    }
};
