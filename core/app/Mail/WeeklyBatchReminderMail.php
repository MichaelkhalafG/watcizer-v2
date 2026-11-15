<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * "Next week's articles aren't written yet — run the next batch" (Michael, 2026-11-14; App\Domain\Content\WeeklyBatch):
 * to the shop's report address on day 6 of a publishing week, with next week's 7 topics from the map and the queue.
 * Internal, in plain English.
 */
final class WeeklyBatchReminderMail extends Mailable
{
    /** @param  list<array<string, string>>  $topics */
    public function __construct(
        private readonly string $shop,
        private readonly string $weekStart,
        private readonly int $nextWeekQueued,
        private readonly int $queued,
        private readonly array $topics,
    ) {}

    public function build(): self
    {
        $items = '';
        foreach ($this->topics as $t) {
            $items .= '<li>'.e(($t['id'] ?? '').' — '.($t['title_en'] ?? '').' (query: '.($t['query_en'] ?? '').' / '.($t['query_ar'] ?? '').')').'</li>';
        }
        $p = fn (string $s): string => '<p style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.6">'.$s.'</p>';

        return $this->subject("[{$this->shop}] Next week's articles aren't written yet — run the next batch")
            ->html($p(e("Next week's articles aren't written yet — run the next batch.")).
                $p(e("{$this->shop}: the week from {$this->weekStart} (Cairo) has {$this->nextWeekQueued} of 7 articles scheduled; {$this->queued} approved articles are still queued in all.")).
                $p(e("Next week's 7 topics from the topic map:")).'<ol style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.6">'.$items.'</ol>'.
                $p(e('Say "write next week\'s batch" to have them written, gated and scheduled.')));
    }
}
