<?php

namespace App\Console\Commands;

use App\Domain\Customers\CustomerTokens;
use App\Support\LegacyJwt;
use Illuminate\Console\Command;

/**
 * tokens:prune — delete revocations whose tokens have expired anyway (M1t, Phase 1 piece 2).
 *
 * A row in `core_revoked_tokens` is load-bearing only until its token's own `exp`. After that the
 * clock refuses the token regardless, and the row answers a question nobody can ask. Without this
 * the table would grow one row per sign-out for ever — slowly, invisibly, and on shared hosting.
 *
 * Deliberately NOT clever: no batching, no `--older-than` grace. The predicate is one indexed
 * range on `expires_at` and the set it deletes is bounded by how many people signed out inside one
 * token lifetime, which for this shop is a number in the hundreds.
 *
 * Deleting a row here can never sign anybody out or back in: the token it described is already
 * expired, so {@see LegacyJwt::claims()} refuses it before revocation is ever asked
 * about. That is the property that makes this safe to run unattended.
 */
final class TokensPruneCommand extends Command
{
    protected $signature = 'tokens:prune';

    protected $description = 'Delete revoked-token records whose tokens have expired anyway';

    public function handle(): int
    {
        $deleted = CustomerTokens::prune();
        $epochs = CustomerTokens::pruneEpochs();

        $this->line("tokens:prune — removed {$deleted} expired revocation(s) and {$epochs} spent epoch(s).");

        return self::SUCCESS;
    }
}
