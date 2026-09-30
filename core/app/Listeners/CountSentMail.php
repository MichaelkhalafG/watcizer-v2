<?php

namespace App\Listeners;

use App\Domain\Notifications\MailBudget;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Counts every message that leaves, per day and bucket (`MailBudget`) — whichever code sent it.
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
            $bucket = $event->message->getHeaders()->get(MailBudget::BULK_HEADER)?->getBodyAsString() === MailBudget::BULK
                ? MailBudget::BULK
                : MailBudget::TRANSACTIONAL;
            MailBudget::record($bucket);
        } catch (Throwable $e) {
            Log::warning('mail count not recorded: '.$e->getMessage());
        }
    }
}
