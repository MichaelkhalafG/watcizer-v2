<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M1i — add `shipped` and `delivered` to the shared `orders.status` enum
 * (developer decision 2026-09-12).
 *
 * ── Why this is a schema change and not a label ──────────────────────────────────────────────
 *
 * The enum held `pending / processing / completed / cancelled`, so a dashboard that wanted to say
 * "shipped" had to say `completed` instead. That is not a naming compromise: the legacy dashboard
 * e-mails the customer on EVERY status change (`Admin/OrderController::update()`), and
 * `completed`'s copy reads *"Your order is complete. Thank you for shopping with Watchizer!"* /
 * *"تم اكتمال طلبك. شكراً لتسوقك…"*. Advancing at shipment time therefore told a customer their
 * order was finished while the watch was still in a van — a wrong message to a real person.
 *
 * The e-mail template already carries `shipped` and `delivered` copy in both languages, unreachable
 * because the enum could not hold the values. This migration makes it reachable.
 *
 * ── Additive, and ORDERED to match the flow ──────────────────────────────────────────────────
 *
 * The two values are inserted BETWEEN `processing` and `completed`, not appended. MariaDB sorts an
 * enum by ORDINAL, so `ORDER BY status` follows the fulfilment order — and any existing report or
 * index that sorts by this column keeps sorting sensibly instead of putting "shipped" after
 * "cancelled". No existing value changes ordinal position relative to the ones before it:
 * pending 1, processing 2 stay; completed moves 3 → 5 and cancelled 4 → 6.
 *
 * **Nothing stored changes.** No row holds a value being removed — there is no removal — and the
 * NOT NULL / DEFAULT 'pending' properties are restated exactly as they were, because MODIFY
 * replaces the whole column definition and dropping either would be a silent behaviour change on
 * a table the legacy app still writes.
 *
 * ── This is one of the few writes core makes to a SHARED table's SCHEMA ──────────────────────
 *
 * `orders` is shared: the legacy Blade dashboard and the legacy checkout write it until the
 * switch. An enum WIDENING is the safe direction — every value legacy knows still validates, and
 * legacy's own `in:` validator is what stops it writing the new ones by accident. The unsafe
 * direction (narrowing, or renaming) is not taken here and must never be.
 *
 * **What legacy does with an unseen value was measured before this ran** (full table in
 * `docs/wave4c/ORDER_STATUS_2026-09-12.md`): the Blade list and show views fall through an
 * if/elseif chain to `@else → "Cancelled"`, and the edit form's four hard-coded options match
 * none, so the select presents `pending`. Both are DISPLAY defects in `backend/`, they are named
 * in that document with their one-line fixes, and they are the reason the runbook orders this
 * migration alongside the legacy validator change rather than before it.
 */
return new class extends Migration
{
    /** The enum in fulfilment order. `shipped` and `delivered` sit where the flow puts them. */
    private const AFTER = "'pending','processing','shipped','delivered','completed','cancelled'";

    private const BEFORE = "'pending','processing','completed','cancelled'";

    public function up(): void
    {
        if (self::has('shipped') && self::has('delivered')) {
            return;     // idempotent: a rehearsal database may already carry the wider enum
        }

        DB::statement(
            'ALTER TABLE `orders` MODIFY `status` ENUM('.self::AFTER.") NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        /*
         * Narrowing an enum SILENTLY TRUNCATES: MariaDB turns a value that no longer exists into
         * the empty string (or errors under a strict mode this database does not run). So the
         * rollback refuses unless the two states are genuinely unused — better a failed `down()`
         * than a table full of orders whose status became ''.
         */
        $stranded = DB::table('orders')->whereIn('status', ['shipped', 'delivered'])->count();
        if ($stranded > 0) {
            throw new RuntimeException(
                "Refusing to narrow orders.status: {$stranded} order(s) are shipped or delivered and would lose their status. "
                .'Move them to another state first.'
            );
        }

        DB::statement(
            'ALTER TABLE `orders` MODIFY `status` ENUM('.self::BEFORE.") NOT NULL DEFAULT 'pending'"
        );
    }

    private static function has(string $value): bool
    {
        $rows = DB::select("SHOW COLUMNS FROM `orders` LIKE 'status'");
        $row = $rows[0] ?? null;
        $type = is_object($row) && property_exists($row, 'Type') ? $row->Type : '';

        return is_string($type) && str_contains($type, "'".$value."'");
    }
};
