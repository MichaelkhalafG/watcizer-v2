<?php

namespace App\Console\Commands;

use App\Compat\Diff\CartCases;
use App\Compat\Diff\DefaultCases;
use App\Compat\Diff\DiffCase;
use App\Compat\Diff\DiffReport;
use App\Compat\Diff\DiffRunner;
use App\Compat\Diff\Probe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * compat:diff — the switch-night tool (CLEAN_CORE_STUDY §3.2 "rehearsable weekly", §8.5/2):
 * calls every compat endpoint on the legacy host and on the core host with the same parameter
 * sets over REAL HTTP and diffs status, contract headers (Content-Type, CORS, Vary) and the full
 * bodies. Byte-identical, or a listed sanctioned deviation (App\Compat\Diff\DeviationRules);
 * anything else fails the run (exit 1).
 *
 *   php artisan compat:diff --legacy=http://127.0.0.1:8011 --compat=http://127.0.0.1:8001
 *   php artisan compat:diff --legacy=https://dash.watchizereg.com --compat=https://api.watchizereg.com --api-key=… --ids=4,71,125
 */
class CompatDiffCommand extends Command
{
    protected $signature = 'compat:diff
        {--legacy= : Base URL of the legacy application (required)}
        {--compat= : Base URL of the core host (required unless --inproc)}
        {--inproc : Run the compat side inside this process instead of over HTTP — BLIND to server headers (CORS, Vary, rate limits); local debugging only, never a sign-off}
        {--api-key= : The Api-Code value (defaults to config compat.api_key)}
        {--ids= : Comma-separated product ids for the products/{id} cases (default: resolved from the database)}
        {--names= : Pipe-separated English titles for the by-name cases (default: resolved from the database)}
        {--output= : Directory for report.md / report.json (default: storage/compat-diff/<timestamp>)}
        {--pace=0 : Milliseconds to wait before every legacy request (the legacy host throttles 60/min per IP)}';

    protected $description = 'Diff the legacy endpoints against the compat layer on the same data over real HTTP (byte-identical or sanctioned deviation)';

    public function handle(): int
    {
        $legacyBase = $this->option('legacy');
        if (! is_string($legacyBase) || $legacyBase === '') {
            $this->error('--legacy=<base url> is required (e.g. http://127.0.0.1:8011).');

            return self::INVALID;
        }
        $compatBase = $this->option('compat');
        $inproc = (bool) $this->option('inproc');
        if ($inproc) {
            $compatBase = 'inproc';
            $this->warn('--inproc: the compat side runs in this process and is BLIND to server headers (CORS, Vary, rate limits). Not a sign-off run.');
            // Error bodies must render as they do in production (no debug trace) to be comparable.
            config(['app.debug' => false]);
        } elseif (! is_string($compatBase) || $compatBase === '') {
            $this->error('--compat=<base url of the core host> is required (or pass --inproc for a header-blind local run).');

            return self::INVALID;
        }
        $apiKey = $this->option('api-key');
        $apiKey = is_string($apiKey) && $apiKey !== '' ? $apiKey : config()->string('compat.api_key');
        $pace = $this->option('pace');
        $pace = is_numeric($pace) ? (int) $pace : 0;

        $ids = $this->option('ids');
        $idList = null;
        if (is_string($ids) && $ids !== '') {
            $idList = array_values(array_map('intval', array_filter(explode(',', $ids), 'is_numeric')));
        }
        $names = $this->option('names');
        $nameList = null;
        if (is_string($names) && $names !== '') {
            $nameList = array_values(array_filter(explode('|', $names), fn (string $n) => $n !== ''));
        }
        $cases = DefaultCases::build($idList, $nameList);

        $output = $this->option('output');
        $dir = is_string($output) && $output !== '' ? $output : storage_path('compat-diff/'.date('Ymd-His'));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->info(sprintf('compat:diff — %d cases, legacy=%s compat=%s', count($cases), $legacyBase, $compatBase));

        // Said BEFORE the run, because the answer changes what the run means (decision 2026-09-12).
        $edited = self::dashboardEditsSinceTransform();
        if ($edited > 0) {
            $this->warn(sprintf(
                'NOTE: %d lookup/category row(s) were edited through the dashboard since the last transform.', $edited,
            ));
            $this->warn(self::REBUILD_FIRST);
            $this->newLine();
        }

        $legacyProbe = new Probe($legacyBase, $apiKey, $pace);
        $compatProbe = new Probe($compatBase, $apiKey);

        $preflight = self::tokenPreflight($legacyProbe, $compatProbe);
        if ($preflight !== null) {
            $this->error($preflight);

            return self::FAILURE;
        }

        $runner = new DiffRunner($legacyProbe, $compatProbe);
        $started = microtime(true);
        $result = $runner->run($cases, fn (string $line) => $this->line('  '.$line));
        $seconds = round(microtime(true) - $started, 1);

        $context = [
            'date' => date('c'),
            'legacy' => $legacyBase,
            'compat' => $compatBase.($inproc ? ' (in-process — header-blind)' : ' (real HTTP)'),
            'cases' => (string) count($cases),
            'duration' => "{$seconds} s",
            'product ids' => implode(', ', array_map(fn (DiffCase $c) => $c->name, array_filter($cases, fn (DiffCase $c) => str_starts_with($c->name, 'product:') && ctype_digit(substr($c->name, 8))))),
        ];
        file_put_contents($dir.'/report.md', DiffReport::markdown($result, $context));
        file_put_contents($dir.'/report.json', json_encode(['context' => $context, 'result' => $result, 'cases' => array_map(fn (DiffCase $c) => $c->toArray(), $cases)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->line('Sanctioned rules absorbed: '.($result['rules'] === [] ? 'none' : implode(', ', array_map(fn (string $k, int $n) => "{$k}×{$n}", array_keys($result['rules']), $result['rules']))));
        $this->line("Report: {$dir}/report.md");
        if ($result['totals']['unexplained_cases'] > 0) {
            /*
             * A run whose ONLY unexplained differences are `updated_at` values is not a divergence
             * between the two implementations — it is a catalogue carrying dashboard edits, whose
             * row timestamps moved while legacy's did not. Rehearsal #3's harness reported 164 of
             * them after one colour and one category node had been renamed, which is 164 lines
             * telling the operator nothing. D-17 is deliberately NOT widened to absorb them
             * (decision 2026-09-12): the harness stays strict, and says the one useful sentence.
             */
            $timestampsOnly = self::everyFindingIsATimestamp($result);
            $this->error($result['verdict']);
            if ($timestampsOnly) {
                $this->newLine();
                $this->warn('EVERY unexplained difference above is an `updated_at` value, and nothing else.');
                $this->warn(self::REBUILD_FIRST);
            }

            return self::FAILURE;
        }
        $this->info($result['verdict']);

        return self::SUCCESS;
    }

    /**
     * Pre-flight (review 🟡-6): do BOTH hosts accept the same minted token?
     *
     * The authenticated cases are only meaningful if the two sides share a `JWT_SECRET`. When
     * they do not, every one of them answers 401 on one host and 200 on the other, and the run
     * ends in a dozen identical "status 401 vs 200" findings that say nothing about the compat
     * layer and bury whatever real difference was in the same report. One request to each host
     * settles it before the first case runs.
     *
     * Returns null when the run may proceed, or the single message explaining why it may not.
     */
    private static function tokenPreflight(Probe $legacy, Probe $compat): ?string
    {
        $reader = CartCases::readOnlyUser();
        if ($reader === null) {
            return null;                      // no secret configured, or no user: the authenticated cases skip themselves
        }

        $case = new DiffCase('preflight:token', 'api/me/orders', 'json', ['Authorization' => 'Bearer '.$reader['token']]);
        $legacyStatus = $legacy->fetch($case)['status'];
        $compatStatus = $compat->fetch($case)['status'];

        if ($legacyStatus === 401 && $compatStatus === 401) {
            return 'Pre-flight: BOTH hosts rejected the harness token (401/401). They do not share a JWT_SECRET, '
                .'or the token subject is not a user on this database. Every authenticated case would be a false '
                .'difference, so the run is stopped before it starts.';
        }
        if ($legacyStatus === 401 || $compatStatus === 401) {
            $accepted = $legacyStatus === 401 ? 'compat' : 'legacy';
            $rejected = $legacyStatus === 401 ? 'legacy' : 'compat';

            return "Pre-flight: only the {$rejected} host rejected the harness token "
                ."(legacy {$legacyStatus}, compat {$compatStatus}). The two hosts are not signing with the same "
                ."JWT_SECRET — the {$accepted} host accepted it and the {$rejected} host did not. Fix the secret "
                .'rather than reading a dozen authenticated cases as compat differences.';
        }
        if ($legacyStatus !== $compatStatus) {
            return 'Pre-flight: the two hosts answered the same authenticated request differently before any case '
                ."ran (legacy {$legacyStatus}, compat {$compatStatus}). That is an environment problem, not a "
                .'compat difference; fix it first.';
        }

        return null;
    }

    /** The one sentence both paths print, so the remedy is worded once. */
    private const REBUILD_FIRST = 'This catalogue carries dashboard edits since the last transform, so the harness '
        .'is not measuring the two implementations — rebuild first, then run it: '
        .'`core:drop-clean --force && migrate --force && core:transform --force` (study §3.4 step 3b, §2.9.4 rule 10).';

    /**
     * How many clean-table rows were written AFTER the last transform, counted only where the
     * answer is UNAMBIGUOUS.
     *
     * `core_transform_id_map` is re-flushed at the end of every run and carries only `created_at`,
     * so its newest row is "when the catalogue was last rebuilt from legacy". A lookup master or a
     * category node with a newer `updated_at` was written by the dashboard, and that is the state
     * that makes a symmetric legacy-vs-compat diff meaningless.
     *
     * Two tables are deliberately NOT counted, both measured on 2026-09-12:
     *
     *  • `catalog_products` and `storefront_product` move for legitimate reasons — the harness's
     *    own checkout cases decrement core's stock, which bumps three product rows and two
     *    storefront rows every run. Counting them would print the warning after every harness run
     *    and teach the operator to ignore it.
     *  • the TRANSLATION tables have no `updated_at` column at all, so a title or name edit is
     *    invisible here however hard this looks. That is why the decisive check is the one at
     *    report time ({@see self::everyFindingIsATimestamp()}), which fires on the symptom
     *    instead of guessing at the cause.
     *
     * Cheap by design (one indexed COUNT per table) and silent when the tables are absent: this
     * check must never be the reason the harness cannot run.
     */
    private static function dashboardEditsSinceTransform(): int
    {
        try {
            if (! Schema::hasTable('core_transform_id_map')) {
                return 0;
            }
            $marker = DB::table('core_transform_id_map')->max('created_at');
            if (! is_string($marker)) {
                return 0;
            }

            $total = 0;
            foreach ([
                'catalog_colors', 'catalog_brands', 'catalog_grades', 'catalog_materials',
                'catalog_shapes', 'catalog_movement_types', 'catalog_closure_types',
                'catalog_display_types', 'catalog_units', 'catalog_genders', 'catalog_features',
                'catalog_sizes', 'storefront_categories',
            ] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'updated_at')) {
                    $total += DB::table($table)->where('updated_at', '>', $marker)->count();
                }
            }

            return $total;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * True when the run failed AND every unexplained finding is a timestamp.
     *
     * @param  array<string, mixed>  $result
     */
    private static function everyFindingIsATimestamp(array $result): bool
    {
        $cases = $result['cases'] ?? null;
        if (! is_array($cases)) {
            return false;
        }

        $seen = 0;
        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }
            $findings = $case['unexplained'] ?? null;
            if (! is_array($findings)) {
                continue;
            }
            foreach ($findings as $finding) {
                $path = is_array($finding) ? ($finding['path'] ?? '') : '';
                $path = is_string($path) ? $path : '';
                if (! str_ends_with($path, 'updated_at') && ! str_ends_with($path, 'created_at')) {
                    return false;
                }
                $seen++;
            }
        }

        return $seen > 0;
    }
}
