<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use RuntimeException;

/**
 * A storefront whose e-mail cannot leave: no `storefront_mailboxes` row, the row is disabled, or it
 * lacks something a send needs (W3, no-fallback rule, developer 2026-10-12).
 *
 * Nothing resolves to another shop's mailbox. The caller decides what "no mailbox" means for its
 * job: an order row is RETRIED on the normal ladder and then parked (the row is the obligation, and
 * a mailbox is minutes to fix — unlike an identity, which is a decision); a bulk row waits; an
 * account mail is logged and the action succeeds. The message names the fields, never a value.
 */
final class StorefrontHasNoMailbox extends RuntimeException
{
    /** @param  list<string>  $missing */
    public static function for(int $storefrontId, array $missing): self
    {
        $what = $missing === [] ? 'no storefront_mailboxes row' : 'storefront_mailboxes row missing '.implode(', ', $missing);

        return new self("storefront {$storefrontId} has no usable mailbox: {$what} (no fallback to another shop)");
    }
}
