<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

/**
 * Why a transform test SKIPS instead of failing when the ledger is dirty (review 2026-09-10 🟡-6).
 *
 * `core:transform` refuses to run once `inventory_movements` holds a row it did not write: legacy
 * `products.stock` is no longer the truth at that point, so re-baselining from it would contradict
 * the movements that ARE the truth. That refusal is the wave-3 guard working exactly as designed.
 *
 * The trouble was what it looked like from the outside. Anything that leaves a real movement behind
 * — a `compat:diff` run that places orders, a rehearsal, a probe that dies before its teardown —
 * turned every transform-touching test RED, eighteen of them at once, each reporting a different
 * broken assertion about counts or reconciliation. A reviewer reading that sees a codebase that
 * fails its own suite; the actual message is one sentence long and is about the database's state,
 * not the code's.
 *
 * So these tests now skip with that sentence. A skip is honest here in a way it usually is not:
 * the precondition for the test is a ledger the transform is allowed to write, the fix is a
 * documented one-command rebuild (study §3.4 step 3b), and the CI/rehearsal path always starts from
 * a fresh restore where the ledger is transform-only.
 */
final class LedgerState
{
    public static function skipIfDirty(): void
    {
        $rows = DB::table('inventory_movements')->where('reason', '!=', 'transform')
            ->selectRaw('reason, COUNT(*) AS n')->groupBy('reason')->orderBy('reason')->get();
        if ($rows->isEmpty()) {
            return;
        }

        $total = 0;
        $parts = [];
        foreach ($rows as $row) {
            $n = T::int($row->n ?? 0);
            $total += $n;
            $parts[] = T::str($row->reason ?? '?').' × '.$n;
        }

        Assert::markTestSkipped(
            "ledger holds {$total} non-transform row(s) (".implode(', ', $parts).'); '.
            'core:transform refuses to re-baseline over real stock movement — that is the wave-3 guard, not a bug. '.
            'Rebuild the clean tables (CLEAN_CORE_STUDY §3.4 step 3b: drop, migrate, transform) and run the suite again.'
        );
    }
}
