<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M2c — re-engagement: products the team picks by hand (R4, developer 2026-09-30).
 *
 *  - `core_reengagement_settings.manual_product_ids` — JSON list, in order: the products the team picked
 *    for the NEXT weekly e-mail. Null/empty = the algorithm chooses (the default, unchanged). Cleared
 *    when a run is planned with them, so the week after is automatic again unless someone picks.
 *  - `core_reengagement_runs.manual_product_ids` — what a run was planned with (null = automatic), so
 *    the dashboard can say which weeks the team chose.
 *
 * Additive and nullable; `hasColumn` guards like every M-series migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('core_reengagement_settings', 'manual_product_ids')) {
            Schema::table('core_reengagement_settings', function (Blueprint $t): void {
                $t->text('manual_product_ids')->nullable()->after('team_emails');
            });
        }
        if (! Schema::hasColumn('core_reengagement_runs', 'manual_product_ids')) {
            Schema::table('core_reengagement_runs', function (Blueprint $t): void {
                $t->text('manual_product_ids')->nullable()->after('audience');
            });
        }
    }

    public function down(): void
    {
        foreach (['core_reengagement_settings', 'core_reengagement_runs'] as $table) {
            if (Schema::hasColumn($table, 'manual_product_ids')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('manual_product_ids'));
            }
        }
    }
};
