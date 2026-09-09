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

// The invariant that makes the ledger trustworthy: Σ quantity_delta = the stock column. Reports
// only; a re-base is a deliberate `--fix` run by a human who has read the drift.
Schedule::command('inventory:verify')->dailyAt('03:30');
