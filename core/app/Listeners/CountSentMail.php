<?php

namespace App\Listeners;

use App\Domain\Notifications\MailBudget;
use App\Domain\Notifications\StorefrontMailer;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counts every message that leaves, per day, bucket and STOREFRONT (`MailBudget`) — whichever
 * code sent it. The storefront is the `X-WZ-Storefront` header `StorefrontMailer` stamps; a
 * message sent without it counts under 0.
 *
 * It must never break a send: the message is already on its way when this runs, so a counting
 * failure is logged and swallowed. The cost of a missed count is that bulk mail might send one
 * message more that day, which the transactional reserve absorbs.
 */
final class CountSentMail
{
    public function handle(MessageSent $event): void
    {
        try {
            $headers = $event->message->getHeaders();
            $bucket = $headers->get(MailBudget::BULK_HEADER)?->getBodyAsString() === MailBudget::BULK
                ? MailBudget::BULK
                : MailBudget::TRANSACTIONAL;
            $storefront = $headers->get(StorefrontMailer::STOREFRONT_HEADER)?->getBodyAsString();
            MailBudget::record($bucket, is_numeric($storefront) ? (int) $storefront : 0);
        } catch (Throwable $e) {
            Log::warning('mail count not recorded: '.$e->getMessage());
        }
    }
}
