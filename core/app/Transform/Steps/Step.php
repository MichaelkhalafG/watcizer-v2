<?php

namespace App\Transform\Steps;

use App\Transform\StepResult;
use App\Transform\TransformContext;

/**
 * One numbered step of CLEAN_CORE_STUDY §2.9.2. Steps run in dependency order,
 * each inside its own transaction (or a savepoint of the dry-run transaction).
 */
interface Step
{
    public function number(): int;

    public function name(): string;

    /** The clean table (or tables) this step writes. */
    public function target(): string;

    public function run(TransformContext $ctx, StepResult $result): void;
}
