<?php

namespace App\Transform\Audit;

/**
 * One audit code (A-01 … A-25 from CLEAN_CORE_STUDY §2.9.5, plus the X-codes this
 * rehearsal added) with its count and row-level detail. Every non-zero finding is
 * listed row by row in audit.csv — nothing is summarised away.
 */
final class AuditFinding
{
    /** @var list<array{entity: string, id: string, detail: string}> */
    public array $rows = [];

    /** Free-text explanation of what the number means for the transform. */
    public string $note = '';

    /** Set when the check could not run meaningfully (e.g. partial local image copy). */
    public string $caveat = '';

    public function __construct(
        public readonly string $code,
        public readonly string $title,
        public readonly bool $blocking,
        public readonly string $handling,
    ) {}

    public function add(string $entity, int|string $id, string $detail): void
    {
        $this->rows[] = ['entity' => $entity, 'id' => (string) $id, 'detail' => $detail];
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function blocks(): bool
    {
        return $this->blocking && $this->count() > 0;
    }
}
