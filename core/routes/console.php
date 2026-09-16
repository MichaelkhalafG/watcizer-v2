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
