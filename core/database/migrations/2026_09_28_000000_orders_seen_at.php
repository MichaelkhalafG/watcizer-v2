<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1p — when this operator last looked at the order queue (wave 4D).
 *
 * ── What "new" means, and why it is PER USER ────────────────────────────────────────────────
 *
 * The badge answers "is there anything I haven't seen?", and on a shop floor that question has a
 * different answer for each person. Two people work the same queue: a global "last seen" stamp
 * would let whoever opened the screen first clear the badge for everybody else, so the second
 * person's unseen orders become invisible — which is the exact failure the badge exists to
 * prevent, arriving silently.
 *
 * So the stamp is per user, and it lives beside the operator's other dashboard preference (the
 * locale, M1m) for the same reason that one does: it is a fact about a PERSON using the dashboard,
 * `users` is a legacy table core may not write, and a core-owned table keyed by user id is the
 * shape already established for exactly this.
 *
 * ── Nullable, and what NULL means ───────────────────────────────────────────────────────────
 *
 * NULL is "has never opened the queue", which is true of every operator the moment this ships. It
 * is NOT treated as "everything is new": a badge reading 75 on somebody's first login is noise
 * they will learn to ignore, and a badge people ignore is worse than none. The reader decides;
 * see `Preferences::ordersSeenAt()`.
 *
 * ── Rebuild safety ──────────────────────────────────────────────────────────────────────────
 *
 * `core_user_preferences` is already DASHBOARD-owned and never dropped, so this column inherits
 * that. The guard is `hasColumn`, not `hasTable`: switch night runs `migrate` against a database
 * where the table survives, and an unguarded `$table->timestamp()` dies at "duplicate column".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('core_user_preferences', 'orders_seen_at')) {
            return;
        }

        Schema::table('core_user_preferences', function (Blueprint $table): void {
            $table->timestamp('orders_seen_at')->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('core_user_preferences', 'orders_seen_at')) {
            return;
        }

        Schema::table('core_user_preferences', function (Blueprint $table): void {
            $table->dropColumn('orders_seen_at');
        });
    }
};
