<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * backup:verify — is there a RECENT, PLAUSIBLE dump on disk right now?
 *
 * ── Why this is a different question from "does `core:backup` work" ─────────────────────────
 *
 * `core:backup` working is a property of the code, and it is tested. What matters at 9am is a
 * property of the DISK: that last night's dump exists, is not a 4 KB stub, and is from last night
 * rather than from three weeks ago when the cron entry was last correct.
 *
 * Those fail in ways the command itself cannot report, because when they fail the command did not
 * run at all: a crontab edited during a host migration, a disk that filled, a PHP upgrade that
 * moved the binary, an `.env` change that pointed the dump somewhere nobody looks. Every one of
 * those leaves `core:backup` perfectly functional and the shop with no backups — and the only
 * signal is silence, which reads exactly like success.
 *
 * So this asks the disk, exits non-zero when the answer is wrong, and says which of the three
 * things is wrong. It is safe to run any time: it writes nothing and dumps nothing.
 */
final class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify
        {--max-age=36 : Fail if the newest dump is older than this many hours}
        {--min-mb=1 : Fail if the newest dump is smaller than this many megabytes}
        {--path= : Where to look (default: storage/app/backups)}';

    protected $description = 'Check that a recent, plausibly-sized database dump exists on disk';

    public function handle(): int
    {
        $option = $this->option('path');
        $directory = is_string($option) && $option !== '' ? $option : storage_path('app/backups');

        $this->info('backup:verify');
        $this->line('  looking in  '.$directory);

        if (! is_dir($directory)) {
            return $this->wrong('the backup directory does not exist. Nothing has ever run here.');
        }

        $dumps = glob(rtrim($directory, '\\/').DIRECTORY_SEPARATOR.'*.sql') ?: [];
        if ($dumps === []) {
            return $this->wrong('there is NO dump in that directory. The schedule is not running.');
        }

        // Newest by modification time — the one a restore would actually reach for.
        usort($dumps, static fn (string $a, string $b): int => (int) @filemtime($b) <=> (int) @filemtime($a));
        $newest = $dumps[0];

        $bytes = (int) (@filesize($newest) ?: 0);
        $modified = (int) (@filemtime($newest) ?: 0);
        $ageHours = $modified === 0 ? PHP_INT_MAX : (int) round((time() - $modified) / 3600);

        $megabytes = $bytes / 1048576;
        /*
         * NOT clamped to a minimum of 1. It was, and that made `--max-age=0` silently pass on a
         * one-hour-old dump — a threshold the operator set and the command quietly raised. A guard
         * that overrides the number you gave it is a guard you cannot test and should not trust.
         */
        $maxAge = max(0, (int) $this->option('max-age'));
        $minMb = max(0.01, (float) $this->option('min-mb'));

        $this->line('  newest      '.basename($newest));
        $this->line('  size        '.number_format($megabytes, 1).' MB');
        $this->line('  age         '.$ageHours.' h');
        $this->line('  kept        '.count($dumps).' dump(s)');

        $problems = [];
        if ($ageHours > $maxAge) {
            $problems[] = "the newest dump is {$ageHours} hours old (limit {$maxAge}). "
                .'The schedule has stopped running.';
        }
        if ($megabytes < $minMb) {
            $problems[] = 'the newest dump is only '.number_format($megabytes, 2).' MB (minimum '
                .number_format($minMb, 2).' MB). It is a stub, not a backup.';
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->wrong($problem);
            }

            return self::FAILURE;
        }

        $this->info('  OK — a recent, plausibly-sized dump is on disk.');
        $this->line('');
        $this->line('  This checks EXISTENCE, SIZE and AGE. It does not prove the dump restores —');
        $this->line('  only a restore into a scratch database proves that, and it is worth doing once.');

        return self::SUCCESS;
    }

    private function wrong(string $why): int
    {
        $this->error('  '.$why);

        return self::FAILURE;
    }
}
