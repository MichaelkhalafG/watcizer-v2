<?php

namespace App\Transform;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * All clean-table writes of the transform go through here.
 *
 * Every call carries an EXPLICIT column list (AGENTS.md §2.9 — never $fillable);
 * a row with a missing or extra key throws before anything is sent to the
 * database. Writes are `INSERT … ON DUPLICATE KEY UPDATE` batches keyed on the
 * table's natural/preserved key, which is what makes a re-run converge
 * (study §2.9.1). The counters come from the database itself: MariaDB reports
 * 1 affected row per insert, 2 per changed update and 0 per identical row, so
 * "inserted / updated / unchanged" is measured, not assumed.
 */
final class Writer
{
    /** @var int<1, max> */
    private readonly int $batch;

    public function __construct(private readonly Connection $db, int $batch = 500)
    {
        $this->batch = max(1, $batch);
    }

    /**
     * @param  list<string>  $columns  every key each row must carry, in order
     * @param  non-empty-list<non-empty-string>  $uniqueBy  the key MariaDB dedupes on (must be a UNIQUE/PK)
     * @param  list<string>  $update  columns refreshed on an existing row (never the key, never created_at)
     * @param  list<array<string, mixed>>  $rows
     */
    public function upsert(string $table, array $columns, array $uniqueBy, array $update, array $rows): UpsertResult
    {
        $result = new UpsertResult;
        if ($rows === []) {
            return $result;
        }

        foreach ($update as $col) {
            if (! in_array($col, $columns, true)) {
                throw new InvalidArgumentException("[$table] update column [$col] is not in the explicit column list.");
            }
        }

        $normalised = [];
        foreach ($rows as $i => $row) {
            $normalised[] = self::assertColumns($table, $columns, $row, $i);
        }

        foreach (array_chunk($normalised, $this->batch) as $chunk) {
            $before = $this->db->table($table)->count();
            $affected = $this->db->table($table)->upsert($chunk, $uniqueBy, $update === [] ? [$uniqueBy[0]] : $update);
            $after = $this->db->table($table)->count();

            $inserted = $after - $before;
            $updated = intdiv(max(0, $affected - $inserted), 2);

            $result->inserted += $inserted;
            $result->updated += $updated;
            $result->unchanged += count($chunk) - $inserted - $updated;
        }

        return $result;
    }

    /**
     * Plain batched insert for tables without a natural unique key (the inventory ledger);
     * the caller decides beforehand which rows are new. Returns the number inserted.
     *
     * @param  list<string>  $columns
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(string $table, array $columns, array $rows): int
    {
        $normalised = [];
        foreach ($rows as $i => $row) {
            $normalised[] = self::assertColumns($table, $columns, $row, $i);
        }
        foreach (array_chunk($normalised, $this->batch) as $chunk) {
            $this->db->table($table)->insert($chunk);
        }

        return count($normalised);
    }

    /**
     * Insert one row and return its id (for tables whose id is assigned by the database).
     *
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     */
    public function insertGetId(string $table, array $columns, array $row): int
    {
        return $this->db->table($table)->insertGetId(self::assertColumns($table, $columns, $row, 0));
    }

    /**
     * Update one row by id with an explicit column list; returns 1 if a value changed.
     *
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $values
     */
    public function updateById(string $table, int $id, array $columns, array $values): int
    {
        return $this->db->table($table)->where('id', $id)->update(self::assertColumns($table, $columns, $values, 0));
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function assertColumns(string $table, array $columns, array $row, int $index): array
    {
        $missing = array_diff($columns, array_keys($row));
        $extra = array_diff(array_keys($row), $columns);

        if ($missing !== [] || $extra !== []) {
            throw new InvalidArgumentException(sprintf(
                '[%s] row #%d violates the explicit column list — missing [%s], extra [%s].',
                $table, $index, implode(', ', $missing), implode(', ', $extra),
            ));
        }

        $ordered = [];
        foreach ($columns as $col) {
            $ordered[$col] = $row[$col];
        }

        return $ordered;
    }
}
