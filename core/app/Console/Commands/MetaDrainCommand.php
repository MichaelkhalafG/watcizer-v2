<?php

namespace App\Console\Commands;

use App\Domain\Analytics\MetaConversions;
use Illuminate\Console\Command;

/**
 * meta:drain — send the card `Purchase` events that are due (B2, 2026-09-29). Every minute from
 * routes/console.php: the retry path for an event Meta did not accept the first time, and the send
 * path for one the callback left pending. Each row is claimed before it is sent, so an overlapping
 * tick or a callback's own flush can never send it twice.
 */
final class MetaDrainCommand extends Command
{
    protected $signature = 'meta:drain';

    protected $description = 'Send pending Meta Conversions API events (card Purchases)';

    public function handle(MetaConversions $meta): int
    {
        if (! MetaConversions::enabled()) {
            return self::SUCCESS;
        }
        $r = $meta->drain();
        if ($r['sent'] + $r['not_sent'] > 0) {
            $this->line("meta:drain — sent {$r['sent']}, not accepted {$r['not_sent']} (see meta:capi-check).");
        }

        return self::SUCCESS;
    }
}
