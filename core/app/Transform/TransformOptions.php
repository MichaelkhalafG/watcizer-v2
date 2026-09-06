<?php

namespace App\Transform;

final class TransformOptions
{
    /**
     * @param  list<int>  $only  step numbers to run (empty = all 21)
     */
    public function __construct(
        public readonly bool $dryRun,
        public readonly bool $auditOnly,
        public readonly array $only,
        public readonly string $imagesRoot,
        public readonly int $chunk,
        public readonly string $outputDir,
    ) {}

    public function runsStep(int $number): bool
    {
        return $this->only === [] || in_array($number, $this->only, true);
    }
}
