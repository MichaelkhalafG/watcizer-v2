<?php

/*
 * ── No stringified data straight into dangerouslySetInnerHTML (security audit, Finding 3) ─────
 *
 * The storefront rendered JSON-LD as
 *
 *     <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(x) }} />
 *
 * in EIGHT places (the audit found one). `JSON.stringify` does not escape `<`, so a stored name
 * containing `</script>` closed the block and ran as markup. Proven on 2026-09-23 against a real
 * build: a brand named `Zeta</script><img src=x onerror=window.__pwned=1>` set `window.__pwned`
 * on its listing page — where the customer JWT sits in sessionStorage for 30 days. Through
 * `safeJsonLd()` the same page stays inert, and JSON.parse still reads the same name.
 *
 * The storefront has no test runner, so the guard lives here, in the suite that runs on every
 * change. It scans the storefront's SOURCE for the shape, not a list of files, so a new page
 * written the old way fails here rather than on a customer.
 */

/**
 * Every .js/.jsx source file of the storefront, excluding build output and dependencies.
 *
 * @return list<string>
 */
function storefrontSources(): array
{
    $root = realpath(base_path('../Frontend-next'));
    if ($root === false) {
        return [];
    }
    $files = [];
    foreach (['src', 'app'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && in_array($file->getExtension(), ['js', 'jsx', 'ts', 'tsx'], true)) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('finds the storefront source at all — a scan of nothing would pass', function () {
    expect(count(storefrontSources()))->toBeGreaterThan(50);
});

it('feeds no JSON.stringify output straight into dangerouslySetInnerHTML', function () {
    $offenders = [];
    foreach (storefrontSources() as $path) {
        foreach (explode("\n", (string) file_get_contents($path)) as $n => $line) {
            if (preg_match('/__html\s*:\s*JSON\.stringify\s*\(/', $line) === 1) {
                $offenders[] = basename(dirname($path)).'/'.basename($path).':'.($n + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('routes every application/ld+json block through safeJsonLd', function () {
    // The positive form: a JSON-LD script whose __html is anything OTHER than safeJsonLd(...) is
    // either the old shape by another name or a new sink nobody has reasoned about.
    $unsafe = [];
    foreach (storefrontSources() as $path) {
        $source = (string) file_get_contents($path);
        if (! str_contains($source, 'application/ld+json')) {
            continue;
        }
        preg_match_all('/__html\s*:\s*([A-Za-z_][A-Za-z0-9_.]*)\s*\(/', $source, $m);
        foreach ($m[1] as $fn) {
            if ($fn !== 'safeJsonLd') {
                $unsafe[] = basename($path).' uses '.$fn.'()';
            }
        }
    }

    expect($unsafe)->toBe([]);
});
