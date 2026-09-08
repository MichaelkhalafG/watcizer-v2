<?php

namespace Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The wave-1 fixture recipe (tests/Feature/Transform/AuditProofTest.php), extracted so a second
 * suite can use it without redeclaring it: a session-scoped TEMPORARY table that shadows a legacy
 * table for THIS connection only. The legacy base table is never written, renamed or dropped —
 * `ALTER TABLE fx_x RENAME TO x` renames the TEMPORARY table, which then hides the base table for
 * the rest of the session; `DROP TEMPORARY TABLE x` puts the base table back.
 *
 * Callers assert the legacy digest (computed on the DEFAULT connection, which never sees the
 * shadow) is unchanged around the whole exercise.
 */
final class LegacyShadow
{
    /** @var list<string> */
    private static array $open = [];

    /**
     * Copy a legacy table into a session shadow and hand the caller a builder factory for it.
     * A factory, not a builder: a reused builder stacks its where clauses and silently no-ops.
     *
     * @param  callable(callable(): Builder): void  $inject
     */
    public static function open(string $table, callable $inject): void
    {
        $c = DB::connection('legacy');
        $c->statement("CREATE TEMPORARY TABLE fx_$table LIKE `$table`");
        $c->statement("INSERT INTO fx_$table SELECT * FROM `$table`");
        $c->statement("ALTER TABLE fx_$table RENAME TO `$table`");
        self::$open[] = $table;
        $inject(fn () => $c->table($table));
    }

    /** Drop every shadow this test opened, newest first; the base tables reappear untouched. */
    public static function closeAll(): void
    {
        foreach (array_reverse(self::$open) as $table) {
            DB::connection('legacy')->statement("DROP TEMPORARY TABLE IF EXISTS `$table`");
        }
        self::$open = [];
    }
}
