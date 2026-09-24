<?php

namespace App\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * "What am I about to write to, and is it a local copy?" — asked once, for every tool that writes.
 *
 * ── Why this class exists ────────────────────────────────────────────────────────────────────
 *
 * The 2026-09-15 sweep found four tools that create rows in the legacy commerce tables as a side
 * effect of being run: `compat:diff` and its launcher, `inventory:prove-release-race`, and
 * `payment:prove-callback-race`. Every one of them was invisible for the same reason — none had
 * ever actually been run, so nothing it did had ever been observed. `compat:diff` alone leaves
 * six orders, six order_items, three carts and three addresses per pass.
 *
 * The first guard written for this (on `compat:diff`, the same day) asked only about the target
 * URL. That was right for the tool in front of it and wrong as a pattern, because it answers "which
 * HOST will serve this request" and the question that matters is "which DATABASE will hold the row".
 * Those come apart in both directions:
 *
 *   • a LOOPBACK harness run still writes production rows if this app's `DB_HOST` points at
 *     production — the URL guard would have waved it through;
 *   • the two race probes take no URL at all, so a URL-shaped guard has nothing to inspect and
 *     would have had to be a third, differently-shaped check.
 *
 * So the question is asked here, once, in both forms, and the three commands share the wording,
 * the loopback list and the opt-in flag. One mechanism, not three variations.
 *
 * ── What counts as local ────────────────────────────────────────────────────────────────────
 *
 * Loopback, and an `APP_ENV` that is not production. Both, because they fail in different
 * directions: a production box usually runs its own database on 127.0.0.1, where the host test
 * alone says "local" about the live shop; and a developer pointed at a remote rehearsal server is
 * not in production but is not local either.
 *
 * It is an ALLOW-list on purpose. A deny-list of known production domains is a list somebody
 * forgets to add the new staging clone to, and the clone nobody listed is the one that matters.
 */
final class WriteTarget
{
    public const LOOPBACK = ['127.0.0.1', 'localhost', '::1', '[::1]', '0.0.0.0'];

    /** Is this host one of ours, on this machine? */
    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host));

        return in_array($host, self::LOOPBACK, true) || str_starts_with($host, '127.');
    }

    /**
     * Every target of this run that is NOT a local copy.
     *
     * @param  array<string, string|null>  $urls  option name => URL the run will write through
     * @return array<string, string> label => what is wrong with it
     */
    public static function remote(array $urls = []): array
    {
        $remote = [];

        foreach ($urls as $option => $url) {
            if (! is_string($url) || $url === '') {
                continue;                     // absent or malformed: it fails later, better explained
            }
            $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
            if ($host !== '' && ! self::isLocalHost($host)) {
                $remote['--'.$option] = $host;
            }
        }

        // The database is a target of EVERY tool here, whether or not it also takes a URL.
        $configured = config('database.connections.'.DB::getDefaultConnection().'.host');
        $dbHost = is_string($configured) ? $configured : '';
        if ($dbHost !== '' && ! self::isLocalHost($dbHost)) {
            $remote['database'] = $dbHost.' (DB_HOST is not this machine)';
        }
        if (app()->environment('production')) {
            $remote['APP_ENV'] = 'production';
        }

        return $remote;
    }

    /**
     * Print the shared refusal and return true, so a caller reads `if (...) { return INVALID; }`.
     *
     * The wording is here rather than in each command because a refusal somebody meets at 02:00
     * has to say the same three things every time: what is not local, what the run would create,
     * and what to do instead. Three commands writing that themselves is three chances to leave one
     * of them out — and the one left out is always the consequence.
     *
     * @param  array<string, string>  $remote  from {@see self::remote()}
     * @param  list<string>  $creates  what a run writes, in the operator's words
     * @param  list<string>  $remedies  what to do instead, most ordinary first
     */
    public static function refuse(Command $command, string $tool, array $remote, array $creates, array $remedies): bool
    {
        $command->getOutput()->error("REFUSING to run: {$tool} WRITES, and this is not a local copy.");

        foreach ($remote as $label => $why) {
            $command->getOutput()->writeln("  {$label} → {$why}");
        }

        $command->getOutput()->writeln('');
        $command->getOutput()->writeln('  What a run creates, every time, in the target database:');
        foreach ($creates as $line) {
            $command->getOutput()->writeln('    • '.$line);
        }

        $command->getOutput()->writeln('');
        $command->getOutput()->writeln('  Do one of these instead:');
        foreach ($remedies as $line) {
            $command->getOutput()->writeln('    • '.$line);
        }
        $command->getOutput()->writeln('');

        return true;
    }

    /**
     * The flag every one of these tools uses to say "yes, I mean it".
     *
     * Each command still spells its own `{--allow-remote : …}` description, because what a run
     * creates differs and a shared description would have to be vague about the thing that matters.
     * What keeps them from drifting to `--force` or `--yes-really` is not a constant they could
     * ignore but `WriteTargetGuardTest`, which reads all three sources and requires this flag, this
     * question and this refusal in each.
     */
    public const ALLOW_REMOTE = 'allow-remote';
}
