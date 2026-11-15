<?php

namespace App\Console\Commands;

use App\Domain\Reviews\Reviews;
use Illuminate\Console\Command;

/**
 * Ask the customers of recently delivered orders for a review (SEO Phase 5, 2026-11-14) — scheduled daily at 11:00
 * (the app's time zone). A request is created for every order that is due; its e-mail goes out only while
 * REVIEW_REQUESTS_ENABLED is on (otherwise the team can still send the link on WhatsApp from the dashboard).
 * `--no-mail` creates the requests without sending.
 */
final class ReviewsRequestCommand extends Command
{
    protected $signature = 'reviews:request {--no-mail}';

    protected $description = 'Create the review requests of delivered orders that are due, and e-mail them when switched on';

    public function handle(): int
    {
        $r = Reviews::requestDue(! $this->option('no-mail'));
        $this->line(sprintf('reviews: %d requests created, %d e-mailed, %d not e-mailed (no address, or e-mail switched off: REVIEW_REQUESTS_ENABLED=%s)',
            $r['created'], $r['sent'], $r['skipped'], config('reviews.requests_enabled') ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
