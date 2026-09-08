<?php

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Session-level read-only enforcement for the `legacy` connection (milestone audit, the raw-SQL
 * vector).
 *
 * The model guard (App\Models\Legacy\LegacyModel) refuses every Eloquent write path, and the
 * transform's reader (App\Transform\LegacySource) puts its own session into read-only mode. Both
 * were bypassable the same way: `DB::connection('legacy')->statement('UPDATE …')` from anywhere
 * else in the app — the compat layer alone opens that connection in ten places for the shared
 * commerce tables. This closes it at the connection itself: as soon as PDO connects, the session
 * gets `tx_read_only = 1`, so the SERVER refuses every write on it (error 1792), whatever the
 * calling code does.
 *
 * Registered in AppServiceProvider on Illuminate\Database\Events\ConnectionEstablished.
 *
 * Not armed while the test suite runs, and that is a deliberate, bounded exception:
 * `DatabaseTransactions` opens a transaction on the `legacy` connection before any test body
 * runs, MariaDB refuses to change `tx_read_only` inside an open transaction, and a read-only
 * transaction also refuses `CREATE TEMPORARY TABLE` — which is exactly how the audit proofs
 * shadow legacy tables without touching them. The suite therefore keeps its own guarantee: every
 * transform/audit test asserts the 65-table legacy digest is unchanged, and
 * tests/Feature/LegacyReadOnlyConnectionTest.php proves this mechanism itself on a separate
 * connection to the same database.
 *
 * The durable fix, once the local privilege table is repaired and production credentials are
 * issued, is a SELECT-only database user for this connection (AGENTS.md §3).
 */
final class LegacyReadOnly
{
    public static function enforce(Connection $connection): void
    {
        $connection->statement('SET SESSION tx_read_only = 1');

        $row = $connection->selectOne('SELECT @@session.tx_read_only AS ro');
        $value = is_object($row) && property_exists($row, 'ro') ? $row->ro : null;
        if ((int) (is_scalar($value) ? $value : 0) !== 1) {
            throw new RuntimeException('Could not put the [legacy] connection into read-only mode (tx_read_only stayed 0). Refusing to serve requests on a writable legacy session.');
        }
    }

    public static function isEnforced(Connection $connection): bool
    {
        $row = $connection->selectOne('SELECT @@session.tx_read_only AS ro');
        $value = is_object($row) && property_exists($row, 'ro') ? $row->ro : null;

        return (int) (is_scalar($value) ? $value : 0) === 1;
    }
}
