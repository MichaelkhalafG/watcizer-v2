<?php

namespace App\Console\Commands;

use App\Compat\Diff\DefaultCases;
use App\Compat\Diff\DiffCase;
use App\Compat\Diff\DiffReport;
use App\Compat\Diff\DiffRunner;
use App\Compat\Diff\Probe;
use Illuminate\Console\Command;

/**
 * compat:diff — the switch-night tool (CLEAN_CORE_STUDY §3.2 "rehearsable weekly", §8.5/2):
 * calls every compat endpoint on the legacy host and on the core host with the same parameter
 * sets and diffs the full responses. Byte-identical, or a listed sanctioned deviation
 * (App\Compat\Diff\DeviationRules); anything else fails the run (exit 1).
 *
 *   php artisan compat:diff --legacy=http://127.0.0.1:8010 --api-key=…            (compat in-process)
 *   php artisan compat:diff --legacy=https://dash.watchizereg.com --compat=https://api.watchizereg.com --api-key=… --ids=4,71,125
 */
class CompatDiffCommand extends Command
{
    protected $signature = 'compat:diff
        {--legacy= : Base URL of the legacy application (required)}
        {--compat=inproc : Base URL of the core host, or "inproc" to run the compat side in this process}
        {--api-key= : The Api-Code value (defaults to config compat.api_key)}
        {--ids= : Comma-separated product ids for the products/{id} cases (default: resolved from the database)}
        {--names= : Pipe-separated English titles for the by-name cases (default: resolved from the database)}
        {--output= : Directory for report.md / report.json (default: storage/compat-diff/<timestamp>)}
        {--pace=0 : Milliseconds to wait before every legacy request (the legacy host throttles 60/min per IP)}';

    protected $description = 'Diff the legacy endpoints against the compat layer on the same data (byte-identical or sanctioned deviation)';

    public function handle(): int
    {
        $legacyBase = $this->option('legacy');
        if (! is_string($legacyBase) || $legacyBase === '') {
            $this->error('--legacy=<base url> is required (e.g. http://127.0.0.1:8010).');

            return self::INVALID;
        }
        $compatBase = $this->option('compat');
        $compatBase = is_string($compatBase) && $compatBase !== '' ? $compatBase : 'inproc';
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

        if ($compatBase === 'inproc') {
            // Error bodies must render as they do in production (no debug trace) to be comparable.
            config(['app.debug' => false]);
        }

        $this->info(sprintf('compat:diff — %d cases, legacy=%s compat=%s', count($cases), $legacyBase, $compatBase));
        $runner = new DiffRunner(new Probe($legacyBase, $apiKey, $pace), new Probe($compatBase, $apiKey));
        $started = microtime(true);
        $result = $runner->run($cases, fn (string $line) => $this->line('  '.$line));
        $seconds = round(microtime(true) - $started, 1);

        $context = [
            'date' => date('c'),
            'legacy' => $legacyBase,
            'compat' => $compatBase,
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
            $this->error($result['verdict']);

            return self::FAILURE;
        }
        $this->info($result['verdict']);

        return self::SUCCESS;
    }
}
