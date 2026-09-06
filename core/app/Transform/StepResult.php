<?php

namespace App\Transform;

final class StepResult
{
    public int $read = 0;

    public UpsertResult $writes;

    /** @var list<string> */
    public array $notes = [];

    public float $durationMs = 0.0;

    /** @var array<string, int> extra named counters (e.g. "covers" => 341) */
    public array $counters = [];

    public function __construct(public readonly int $number, public readonly string $name, public readonly string $target)
    {
        $this->writes = new UpsertResult;
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    public function count(string $name, int $delta = 1): void
    {
        $this->counters[$name] = ($this->counters[$name] ?? 0) + $delta;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'step' => $this->number,
            'name' => $this->name,
            'target' => $this->target,
            'read' => $this->read,
            'inserted' => $this->writes->inserted,
            'updated' => $this->writes->updated,
            'unchanged' => $this->writes->unchanged,
            'counters' => $this->counters,
            'notes' => $this->notes,
            'duration_ms' => round($this->durationMs, 1),
        ];
    }
}
