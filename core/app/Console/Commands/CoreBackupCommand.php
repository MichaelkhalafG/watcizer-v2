<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * core:backup — one nightly dump of the one database, kept for a few days, outside the web root.
 *
 * ── What this file contains, and why that decides everything else ───────────────────────────
 *
 * EVERY CUSTOMER'S NAME, E-MAIL, PHONE NUMBER AND POSTAL ADDRESS, plus every bcrypt password hash
 * in `users`. It is the most sensitive artefact this project produces — more sensitive than the
 * database itself, because a file can be copied somewhere convenient and forgotten, and a database
 * cannot. Three properties follow from that and none of them is optional:
 *
 *  1. **Outside the web root.** A dump under `public/` is one guessed URL away from being
 *     downloaded by anybody. The command REFUSES to write anywhere beneath the public directory,
 *     rather than trusting that nobody will point `--path` there.
 *  2. **0600.** Readable by the account that owns the site and nobody else on a shared host.
 *     **On Windows this does nothing** — PHP's `chmod()` is a near no-op on NTFS, and a local run
 *     leaves the dump at whatever the directory inherits (measured: 0644). That is fine on the
 *     developer's own machine and would not be fine on the host, so the property is stated here
 *     rather than assumed: the mode matters on Linux, which is where the scheduled run happens.
 *  3. **Retention.** Old dumps are deleted automatically, because the risk of a copy lying around
 *     grows with every night nobody looks.
 *
 * ── Deliberately NOT encrypted (developer decision, 2026-09-15) ─────────────────────────────
 *
 * An encryption key sitting in `.env` beside the dump on the same host is not protection: whoever
 * can read one can read the other. It would buy the feeling of security and the certainty of an
 * unrestorable backup the day the key changes. The practical controls above are the real ones.
 *
 * ── Why the temp-file dance ─────────────────────────────────────────────────────────────────
 *
 * A shared host kills long processes. A dump interrupted halfway is a FILE THAT EXISTS, has a
 * plausible size, and restores into a half-empty database — the worst possible failure, because
 * it looks like a backup. So the dump is written under a `.part` name, its completion marker is
 * checked, and only then is it renamed into place. A file without the marker never becomes a
 * backup, and the run reports failure loudly.
 *
 * ── The password never appears on the command line ──────────────────────────────────────────
 *
 * `ps` is readable by every user on a shared host, so `--password=…` would publish the database
 * password to anyone logged in. It goes through `MYSQL_PWD` in the child process's environment
 * instead.
 */
final class CoreBackupCommand extends Command
{
    protected $signature = 'core:backup
        {--keep=7 : Delete dumps older than this many days}
        {--path= : Directory for the dump (default: storage/app/backups). Never inside public/}
        {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Dump the database to a private directory, prune old dumps, and log the outcome';

    /** What `mysqldump` writes as its last line when it finished cleanly. */
    private const COMPLETION_MARKER = 'Dump completed';

    public function handle(): int
    {
        $started = microtime(true);

        $directory = $this->directory();
        if ($directory === null) {
            return self::FAILURE;
        }

        $connection = DB::getDefaultConnection();
        $config = config()->array('database.connections.'.$connection);

        $database = is_string($config['database'] ?? null) ? $config['database'] : '';
        if ($database === '') {
            $this->error('No database configured for connection ['.$connection.'].');

            return self::FAILURE;
        }

        $stamp = date('Ymd-His');
        $final = $directory.DIRECTORY_SEPARATOR.$database.'-'.$stamp.'.sql';
        $partial = $final.'.part';

        if ($this->option('dry-run') === true) {
            $this->info('core:backup — DRY RUN, nothing written');
            $this->line('  database   '.$database);
            $this->line('  would write '.$final);
            $this->line('  retention  '.$this->keepDays().' days');
            $this->pruneOldDumps($directory, $database, true);

            return self::SUCCESS;
        }

        $this->info('core:backup');
        $this->line('  database   '.$database);
        $this->line('  target     '.$final);

        $process = $this->dumpProcess($config, $database, $partial);
        $process->run();

        if (! $process->isSuccessful()) {
            @unlink($partial);

            return $this->abortWith('mysqldump exited '.$process->getExitCode().': '
                .trim(substr($process->getErrorOutput(), 0, 400)));
        }

        // The marker check. A dump without it was interrupted, whatever the exit code claimed.
        if (! $this->looksComplete($partial)) {
            @unlink($partial);

            return $this->abortWith('the dump has no completion marker — it was cut short and was NOT kept.');
        }

        if (! @rename($partial, $final)) {
            @unlink($partial);

            return $this->abortWith('could not move the finished dump into place.');
        }

        @chmod($final, 0600);

        $bytes = (int) (@filesize($final) ?: 0);
        $seconds = round(microtime(true) - $started, 1);
        $pruned = $this->pruneOldDumps($directory, $database, false);

        $summary = sprintf('core:backup OK — %s, %s MB, %ss, %d old dump(s) pruned',
            basename($final), number_format($bytes / 1048576, 1), $seconds, $pruned);

        $this->info('  '.$summary);

        /*
         * Logged as well as printed, because a scheduled run has no terminal. A silent failure is
         * the thing that turns "we have backups" into "we had backups" — so every run leaves a
         * line, success or not, and `storage/logs/backup.log` is the one file to read.
         */
        $this->record($summary);

        return self::SUCCESS;
    }

    /**
     * Report a failure the same way every time: console, log, exit 1.
     *
     * Not called `fail()` — Laravel's own `Command` already has a public method of that name (it
     * throws), and overriding it privately is a fatal error at class-load time, not a test failure.
     */
    private function abortWith(string $why): int
    {
        $message = 'core:backup FAILED — '.$why;
        $this->error('  '.$why);
        $this->record($message);
        Log::error($message);

        return self::FAILURE;
    }

    private function record(string $line): void
    {
        $path = storage_path('logs/backup.log');
        @file_put_contents($path, '['.date('Y-m-d H:i:s').'] '.$line.PHP_EOL, FILE_APPEND);
        @chmod($path, 0600);
    }

    /**
     * The directory, created if needed — and never inside the web root.
     *
     * Returns null when the target is refused, so the caller can exit without a second check.
     */
    private function directory(): ?string
    {
        $option = $this->option('path');
        $directory = is_string($option) && $option !== ''
            ? $option
            : storage_path('app/backups');

        $public = realpath(public_path());

        /*
         * The web-root check runs BEFORE the directory is created, on the path as given. Creating
         * it first and refusing afterwards left an empty `public/dumps` behind on every refusal —
         * harmless in itself, and exactly the kind of leftover that makes somebody think the path
         * is supported and try again with more determination.
         */
        $absolute = str_starts_with($directory, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:#', $directory) === 1
            ? $directory
            : base_path($directory);

        if (is_string($public) && self::isUnder($absolute, $public)) {
            return $this->refusePublicPath($absolute);
        }

        $resolved = realpath($directory);

        if ($resolved === false) {
            if (! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                $this->error('Cannot create the backup directory: '.$directory);

                return null;
            }
            $resolved = realpath($directory);
        }

        if ($resolved === false) {
            $this->error('Cannot resolve the backup directory: '.$directory);

            return null;
        }

        // Second check, on the RESOLVED path: a symlink under storage/ could still land in public/.
        if (is_string($public) && self::isUnder($resolved, $public)) {
            return $this->refusePublicPath($resolved);
        }

        @chmod($resolved, 0700);

        return $resolved;
    }

    /**
     * Is `$path` inside `$parent`?
     *
     * Separators are normalised first, and on Windows the comparison is case-insensitive. Both
     * matter: `base_path('public/dumps')` returns a path with MIXED separators
     * (`D:\…\core/public/dumps`), so a naive `str_starts_with` against `D:\…\core\public\`
     * silently answers false — which is how the first version of this guard created the very
     * directory it was refusing. A test caught it; a reviewer would not have.
     */
    private static function isUnder(string $path, string $parent): bool
    {
        $normalise = static function (string $value): string {
            $value = str_replace(DIRECTORY_SEPARATOR, '/', $value);
            $value = rtrim($value, '/').'/';

            // Windows paths differ in case without differing as paths.
            return DIRECTORY_SEPARATOR !== '/' ? strtolower($value) : $value;
        };

        return str_starts_with($normalise($path), $normalise($parent));
    }

    /** One wording for both web-root checks — the path as given, and the path after symlinks. */
    private function refusePublicPath(string $path): null
    {
        $this->error('REFUSING: '.$path.' is inside the web root.');
        $this->line('');
        $this->line('  This dump holds every customer name, e-mail, phone and address, and every');
        $this->line('  password hash. Under public/ it is one guessed URL from being downloaded.');

        return null;
    }

    /**
     * @param  array<mixed>  $config
     */
    private function dumpProcess(array $config, string $database, string $target): Process
    {
        $binary = config()->string('database.mysqldump', 'mysqldump');

        $arguments = [
            $binary,
            '--host='.(is_string($config['host'] ?? null) ? $config['host'] : '127.0.0.1'),
            '--port='.(string) (is_scalar($config['port'] ?? null) ? $config['port'] : 3306),
            '--user='.(is_string($config['username'] ?? null) ? $config['username'] : ''),
            // Routines and triggers travel with the schema, or a restore is subtly not the same
            // database. `--single-transaction` keeps InnoDB consistent without locking the shop.
            '--single-transaction',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--result-file='.$target,
            $database,
        ];

        $password = is_string($config['password'] ?? null) ? $config['password'] : '';

        $process = new Process($arguments, base_path(), ['MYSQL_PWD' => $password], null, 3600.0);
        $process->setTimeout(3600.0);

        return $process;
    }

    /** Did mysqldump finish? Its last lines carry a completion marker when it did. */
    private function looksComplete(string $file): bool
    {
        $size = @filesize($file);
        if ($size === false || $size < 1024) {
            return false;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }
        fseek($handle, max(0, $size - 512));
        $tail = (string) fread($handle, 512);
        fclose($handle);

        return str_contains($tail, self::COMPLETION_MARKER);
    }

    /** Delete dumps older than the retention window. Returns how many went. */
    private function pruneOldDumps(string $directory, string $database, bool $dryRun): int
    {
        $cutoff = time() - ($this->keepDays() * 86400);
        $pattern = $directory.DIRECTORY_SEPARATOR.$database.'-*.sql';

        $removed = 0;
        foreach (glob($pattern) ?: [] as $file) {
            $modified = @filemtime($file);
            if ($modified === false || $modified >= $cutoff) {
                continue;
            }
            if ($dryRun) {
                $this->line('  would prune '.basename($file));
                $removed++;

                continue;
            }
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function keepDays(): int
    {
        $keep = $this->option('keep');

        return max(1, is_numeric($keep) ? (int) $keep : 7);
    }
}
