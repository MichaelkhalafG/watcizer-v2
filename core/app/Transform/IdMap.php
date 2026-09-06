<?php

namespace App\Transform;

use Illuminate\Database\Connection;

/**
 * Legacy id → clean id memory for rows whose id is NOT preserved (study §2.9.2
 * step 4: `transform_id_map(source_table, source_id, target_id)`). Loaded from the
 * table at the start of a run and flushed at the end, so every rehearsal hands the
 * same legacy row the same clean id.
 */
final class IdMap
{
    public const TABLE = 'transform_id_map';

    /** @var list<string> */
    private const COLUMNS = ['source_table', 'source_id', 'target_table', 'target_id'];

    /** @var array<string, int> key "source_table|source_id|target_table" */
    private array $map = [];

    /** @var array<string, int> */
    private array $pending = [];

    public function __construct(private readonly Connection $db, private readonly Writer $writer) {}

    public function load(): int
    {
        $this->map = [];
        foreach ($this->db->table(self::TABLE)->select(self::COLUMNS)->orderBy('id')->cursor() as $row) {
            $this->map[self::key(Row::str($row, 'source_table'), Row::int($row, 'source_id'), Row::str($row, 'target_table'))] = Row::int($row, 'target_id');
        }

        return count($this->map);
    }

    public function get(string $sourceTable, int $sourceId, string $targetTable): ?int
    {
        return $this->map[self::key($sourceTable, $sourceId, $targetTable)] ?? null;
    }

    public function remember(string $sourceTable, int $sourceId, string $targetTable, int $targetId): void
    {
        $key = self::key($sourceTable, $sourceId, $targetTable);
        if (($this->map[$key] ?? null) === $targetId) {
            return;
        }
        $this->map[$key] = $targetId;
        $this->pending[$key] = $targetId;
    }

    /** Persist new/changed pairs; returns the upsert counters. */
    public function flush(): UpsertResult
    {
        $rows = [];
        foreach ($this->pending as $key => $targetId) {
            [$sourceTable, $sourceId, $targetTable] = explode('|', $key, 3);
            $rows[] = [
                'source_table' => $sourceTable,
                'source_id' => (int) $sourceId,
                'target_table' => $targetTable,
                'target_id' => $targetId,
            ];
        }
        $this->pending = [];

        return $this->writer->upsert(self::TABLE, self::COLUMNS, ['source_table', 'source_id', 'target_table'], ['target_id'], $rows);
    }

    private static function key(string $sourceTable, int $sourceId, string $targetTable): string
    {
        return "$sourceTable|$sourceId|$targetTable";
    }
}
