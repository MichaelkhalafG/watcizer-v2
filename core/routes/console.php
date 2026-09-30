<?php

// Closure-based console commands are registered here. Application commands live in
// app/Console/Commands (e.g. core:transform, wave 1). Scheduled jobs are defined in
// bootstrap/app.php once the shared-hosting cron path (§5.2.1) is wired.

use Illuminate\Support\Facades\Schedule;

/*
| Wave 3 schedule. Both are cheap, indexed and idempotent, so a missed tick costs nothing and a
| doubled tick changes nothing. They are registered here rather than in bootstrap/app.php so the
| shared-hosting cron entry stays a single `schedule:run` (§5.2.1).
*/

// Legacy defect #6: an order cancelled anywhere — the Blade dashboard, a hand-edited row, a
// future customer-cancel button — gives its reserved stock back within a minute (deviation D-20).
Schedule::command('inventory:reconcile-cancellations')->everyMinute()->withoutOverlapping();

/*
| Unpaid card orders (2026-09-26). A shopper who leaves Paymob's page without paying produces no
| callback, so this is the only thing that gives their reserved stock back. Same cron entry.
*/
Schedule::command('orders:expire-unpaid')->everyMinute()->withoutOverlapping();

// The Morabaa connector does not exist yet, so the outbox is drained by a no-op consumer to keep
// the table bounded (study §4.2). The `mail` channel is deliberately never drained.
Schedule::command('integration:drain --channel=morabaa')->hourly()->withoutOverlapping();

/*
| Order e-mail (prerequisite (a), 2026-09-13). Sending is INLINE by default — the customer's
| confirmation arrives while they are still on the thank-you page — so on a healthy host this tick
| finds nothing due and costs one indexed query. It exists for the unhealthy one: a relay that
| refused, a network that blinked, a process killed mid-send. Every such message is a `pending`
| row with a backoff, and this is what retries it.
|
| `--reclaim` returns rows left `sending` by a killed process. `withoutOverlapping` because a
| tick that runs long must not be joined by the next one; the row CLAIM would refuse the double
| send anyway, which is the belt behind this brace.
|
| It rides the `schedule:run` entry wave 3 already needs for the cancellation reconciler, so
| switch night adds no new crontab line (§5.2.1).
*/
Schedule::command('mail:drain --reclaim')->everyMinute()->withoutOverlapping();

/*
| Bulk mail — restock alerts, and the re-engagement campaign (2026-10-01). Every five minutes,
| within the day's bulk budget only (MAIL_DAILY_CAP − MAIL_TRANSACTIONAL_RESERVE − sent today), so
| order mail above keeps its reserve. Stock alerts past keeping are pruned daily at 03:20, after
| the 03:00 backup.
*/
Schedule::command('bulk-mail:drain')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('stock-alerts:prune')->dailyAt('03:20');

/*
| The weekly re-engagement e-mail (2026-10-01): prices watched daily (so "unchanged for 14 days" is
| a fact), the run planned Monday 10:00 with the team's preview, and sent from the hourly tick once
| its 24 hours have passed — unless the storefront is paused on the dashboard by then.
*/
Schedule::command('reengagement prices')->dailyAt('02:45');
Schedule::command('reengagement plan')->weeklyOn(1, '10:00')->withoutOverlapping();
Schedule::command('reengagement send')->hourly()->withoutOverlapping();

// The invariant that makes the ledger trustworthy: Σ quantity_delta = the stock column. Reports
// only; a re-base is a deliberate `--fix` run by a human who has read the drift.
Schedule::command('inventory:verify')->dailyAt('03:30');

/*
| Nightly database backup (wave 4D, developer decision 2026-09-15).
|
| 03:00, half an hour before `inventory:verify`, so a night that goes wrong leaves the dump taken
| BEFORE the verifier's findings rather than after them. It rides the same one-minute
| `schedule:run` cron entry as everything above, so switch night adds no crontab line.
|
| `withoutOverlapping` because a dump that runs long must not be joined by the next night's — two
| mysqldumps against one shared host is how a backup becomes the outage.
|
| NOT encrypted, by decision: a key in `.env` beside the dump on the same host protects nothing.
| The real controls are in the command — outside the web root, 0600, retention, and a log line
| every run so a silent failure is visible. See `CoreBackupCommand` and study §5.6.
*/
Schedule::command('core:backup --keep=7')->dailyAt('03:00')->withoutOverlapping();

/*
| Revoked-token housekeeping (M1t, storefront Phase 1, 2026-09-21).
|
| Daily at 03:15 — between the backup (03:00) and `inventory:verify` (03:30), so the night's dump
| is taken BEFORE this deletes anything and a mistake here is recoverable from it.
|
| A pruned row can never sign anybody out or back in: its token is already past `exp`, so the clock
| refuses it before revocation is consulted at all. Without the tick the table grows one row per
| sign-out for ever. It rides the same one-minute `schedule:run` entry as everything above.
*/
Schedule::command('tokens:prune')->dailyAt('03:15');

/*
| Guest carts (2026-09-28, C3). The legacy app pruned them; its cron line goes with the legacy site.
| 03:20 — after the backup (03:00), so a mistake is recoverable from that night's dump. Idle = no
| activity on the cart or any of its lines for 30 days; signed-in carts are never touched.
*/
Schedule::command('carts:prune')->dailyAt('03:20')->withoutOverlapping();

/*
| Meta Conversions API (B2, 2026-09-29): the card Purchase events. The payment callback sends each one
| right after its commit; this tick retries any Meta did not accept, with a backoff. Does nothing
| while META_CAPI_TOKEN is empty.
*/
Schedule::command('meta:drain')->everyMinute()->withoutOverlapping();

/*
| The listing index (2026-09-28, CatalogWarmer). It lives 10 minutes (`compat.ttl.all_product`)
| and takes ~1 s to build; rebuilt every 5 it never expires under a shopper, and the stock it
| sorts by is never more than 5 minutes old. A write that makes it stale rebuilds it at once
| (`compat.warm_on_write`); this tick covers everything else. Same `schedule:run` entry.
*/
Schedule::command('catalog:warm')->everyFiveMinutes()->withoutOverlapping();
