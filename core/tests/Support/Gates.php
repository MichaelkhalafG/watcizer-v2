<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The pre-switch gates, put back to REFUSING for the duration of one test (item 5, 2026-09-18).
 *
 * ── Why a helper and not a line in each file ────────────────────────────────────────────────
 *
 * `config('transform.pre_switch_gates')` ships as `warn`: the developer asked for every gated
 * feature open and exercised before switch night, so the refusals became caveats and the controls
 * work. Eleven tests across five files existed to prove those refusals, and they are still worth
 * having — `CORE_PRE_SWITCH_GATES=enforce` is a supported mode and is exactly what somebody reaches
 * for the first time a rebuild eats an afternoon of work. A refusal nobody tests is a refusal
 * nobody can safely turn back on.
 *
 * So each of those tests opts in with one call, and this is the one place that knows the key's
 * name. Spelling it out in five files is five places to update the day the key changes, and four
 * of them would be found by somebody debugging a green test that proves nothing.
 *
 * `config()` writes to the in-memory repository and nothing persists it, so the scope is the test
 * — the same shape as `PreSwitch::allowing()`, which scopes an exemption to one callable.
 */
final class Gates
{
    /** Make the pre-switch gates REFUSE again, for this test only. */
    public static function enforcePreSwitch(): void
    {
        config(['transform.pre_switch_gates' => 'enforce']);
    }
}
