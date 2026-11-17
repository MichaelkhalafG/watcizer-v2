<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * A CREATE TABLE run on a SECOND connection, so the test's own transaction survives it.
 *
 * MariaDB commits the open transaction of a session on any DDL. A command that creates its backup table
 * on first --apply (blog:heroes, reviews:branches) would therefore commit the test's fixtures mid-test,
 * and nothing after that rolls back: products, articles and branch links stay in the pest database and
 * change the counts other tests read (seen 2026-11-17). Creating the table here first, on another
 * session, leaves the command's hasTable() check true, so it never issues its own CREATE; the rows it
 * writes into the (empty) table roll back with the test.
 */
final class SideDdl
{
    /**
     * $freshSnapshot: also restart the test's (still empty) transaction, so its snapshot begins AFTER the table exists.
     * Without it, a test that has already read anything gets MariaDB 1412 ("Table definition has changed") the first
     * time it reads the new table. Only for the FIRST statement of a test: anything written before it is rolled back.
     */
    public static function run(string $sql, bool $freshSnapshot = false): void
    {
        $name = 'side_ddl';
        $default = config('database.default');
        config(["database.connections.{$name}" => config('database.connections.'.(is_string($default) ? $default : 'mariadb'))]);
        DB::connection($name)->statement($sql);
        DB::purge($name);
        if ($freshSnapshot && DB::transactionLevel() === 1) {
            DB::rollBack();
            DB::beginTransaction();
        }
    }
}
