<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M2h — the activity log's search and filters without a full scan (§4.2, 2026-10-13).
 *
 * `core_activity_log` carried three indexes — `(created_at)`, `(user_id, created_at)`,
 * `(subject_type, subject_id)` — one per question the screen asked. Two questions had none and
 * the free-text box had a `LIKE %term%` over two unindexed columns, which is a full scan of a
 * table that grows with every save (7,638 rows locally on 2026-10-13; production unread).
 *
 *  • `(action, created_at)` — "every deletion this month";
 *  • `(storefront_id, created_at)` — the scope every scoped grant applies, and the new filter;
 *  • `(subject_label)` and `(user_name)` — the search is now a PREFIX match (`LIKE 'term%'`),
 *    which these two indexes answer as range scans; `(subject_id)` — a numeric term matches the
 *    record's id directly.
 *
 * Guarded, additive, no data change.
 */
return new class extends Migration
{
    private const INDEXES = [
        'activity_action_idx' => ['action', 'created_at'],
        'activity_storefront_idx' => ['storefront_id', 'created_at'],
        'activity_label_idx' => ['subject_label'],
        'activity_user_name_idx' => ['user_name'],
        'activity_subject_id_idx' => ['subject_id'],
    ];

    public function up(): void
    {
        $existing = self::names();
        Schema::table('core_activity_log', function (Blueprint $t) use ($existing): void {
            foreach (self::INDEXES as $name => $columns) {
                if (! in_array($name, $existing, true)) {
                    $t->index($columns, $name);
                }
            }
        });
    }

    /** @return list<string> the index names the table carries now */
    private static function names(): array
    {
        $out = [];
        foreach (Schema::getIndexes('core_activity_log') as $index) {
            $name = is_array($index) ? ($index['name'] ?? null) : null;
            if (is_string($name)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    public function down(): void
    {
        $existing = self::names();
        Schema::table('core_activity_log', function (Blueprint $t) use ($existing): void {
            foreach (array_keys(self::INDEXES) as $name) {
                if (in_array($name, $existing, true)) {
                    $t->dropIndex($name);
                }
            }
        });
    }
};
