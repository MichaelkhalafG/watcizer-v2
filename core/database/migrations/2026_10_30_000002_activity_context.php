<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M1w — the activity log, made answerable (2026-10-30, Michael's item 3): a `context` on each row, so "bulk changes"
 * is one filter rather than a guess from the label. `ActivityLog::within('bulk' | 'bulk_price', …)` sets it for
 * every row a bulk action writes; every other row leaves it null. Indexed with `created_at` for the filtered,
 * newest-first page. Additive, nullable, guarded (AGENTS §2.20).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('core_activity_log') && ! Schema::hasColumn('core_activity_log', 'context')) {
            Schema::table('core_activity_log', function (Blueprint $t): void {
                $t->string('context', 32)->nullable()->after('action');
                $t->index(['context', 'created_at'], 'activity_context_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('core_activity_log') && Schema::hasColumn('core_activity_log', 'context')) {
            Schema::table('core_activity_log', function (Blueprint $t): void {
                $t->dropIndex('activity_context_idx');
                $t->dropColumn('context');
            });
        }
    }
};
