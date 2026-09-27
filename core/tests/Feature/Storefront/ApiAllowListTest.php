<?php

use App\Http\Controllers\Compat\GoneController;
use App\Http\Controllers\Compat\ProxyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\Support\T;

/*
 * Every API call the storefront makes must get through the production `.htaccess` allow-list
 * (2026-09-27).
 *
 * On `api.watchizereg.com` Apache answers before Laravel: a path missing from the allow-list is a
 * 404 in production while it is in `route:list` and passes every local test, because no local run
 * goes through Apache. `catalog/nav` (C-1 stage 2) shipped exactly like that. The allow-list lives on
 * the server; its repository copy is the block in RUNBOOK_PHASE2_STOREFRONT.md §4.1.1, which this
 * test reads. The calls are read from the storefront's own source, so a new call fails HERE, before
 * a deploy, unless the runbook block (and with it the server file) is widened in the same change.
 */

/** @return list<string> the API host's allowed-path patterns, as PCRE */
function allowListPatterns(): array
{
    $runbook = (string) file_get_contents(base_path('docs/deploy/RUNBOOK_PHASE2_STOREFRONT.md'));
    $start = strpos($runbook, '### 4.1.1');
    expect($start)->not->toBeFalse('runbook §4.1.1 not found');
    $block = substr($runbook, (int) $start);
    $apiRule = substr($block, (int) strpos($block, '# ── the API host'), (int) strpos($block, 'RewriteRule ^ - [R=404,L]') - (int) strpos($block, '# ── the API host'));

    preg_match_all('/RewriteCond %\{REQUEST_URI\} !(\^\S+)/', $apiRule, $m);
    $patterns = array_values(array_filter($m[1], fn (string $p): bool => $p !== '^/index\.php'));
    expect(count($patterns))->toBeGreaterThan(5, 'the API host block was not parsed');

    return array_map(fn (string $p): string => '#'.$p.'#', $patterns);
}

/** @return array<string, array{method: string, path: string, files: list<string>}> keyed "METHOD path" */
function storefrontCalls(): array
{
    $root = dirname(base_path()).DIRECTORY_SEPARATOR.'Frontend-next';
    $calls = [];
    foreach (['src', 'app'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file instanceof SplFileInfo || ! preg_match('/\.(jsx?|mjs)$/', $file->getFilename())) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            // `http.get('x')`, and the chained form `http\n  .post('x', …)`, on the storefront's clients.
            preg_match_all('/\b(?:http|serverHttp|client)\s*\.(get|post|put|patch|delete)\(\s*[`\'"]([^`\'"]+)/', $src, $m, PREG_SET_ORDER);
            foreach ($m as [, $method, $raw]) {
                // A template placeholder stands for one path segment, given a value its route accepts.
                $path = '/api/'.ltrim((string) preg_replace_callback('/\$\{([^}]*)\}/', fn (array $p): string => match (true) {
                    str_contains($p[1], 'STOREFRONT_CODE') => 'watchizer',
                    str_contains($p[1], 'provider') => 'google',
                    default => '1',
                }, $raw), '/');
                $key = strtoupper($method).' '.$path;
                $calls[$key] ??= ['method' => strtoupper($method), 'path' => $path, 'files' => []];
                $calls[$key]['files'][] = $file->getFilename();
            }
        }
    }
    expect(count($calls))->toBeGreaterThan(20, 'the storefront scan found too few calls — has the http client been renamed?');

    return $calls;
}

it('lets every API path the storefront calls through the api host allow-list', function () {
    $patterns = allowListPatterns();
    $blocked = [];
    foreach (storefrontCalls() as $key => $call) {
        $allowed = false;
        foreach ($patterns as $p) {
            if (preg_match($p, $call['path']) === 1) {
                $allowed = true;
                break;
            }
        }
        if (! $allowed) {
            $blocked[] = $key.'  ('.implode(', ', array_unique($call['files'])).')';
        }
    }

    expect($blocked)->toBe([], "Apache would 404 these in production. Widen the API-host block in RUNBOOK_PHASE2_STOREFRONT.md §4.1.1 AND the server's .htaccess:\n".implode("\n", $blocked));
});

it('sends every storefront call to a core route, not the legacy proxy', function () {
    $proxied = [];
    foreach (storefrontCalls() as $key => $call) {
        $route = Route::getRoutes()->match(Request::create($call['path'], $call['method']));
        $action = T::str($route->getActionName());
        if (str_contains($action, ProxyController::class) || str_contains($action, GoneController::class)) {
            $proxied[] = $key.' → '.class_basename($action);
        }
    }

    expect($proxied)->toBe([]);
});

it('would catch a call the allow-list does not name', function () {
    $patterns = allowListPatterns();
    $hit = array_filter($patterns, fn (string $p): bool => preg_match($p, '/api/catalog/not-a-route') === 1);

    // The widened line allows catalog/meta and catalog/nav, and nothing else under catalog/.
    expect($hit)->toBe([])
        ->and(array_filter($patterns, fn (string $p): bool => preg_match($p, '/api/catalog/nav') === 1))->not->toBe([])
        ->and(array_filter($patterns, fn (string $p): bool => preg_match($p, '/manage') === 1))->toBe([]);
});
