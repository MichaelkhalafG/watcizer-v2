<?php

/*
 * ── A core failure is never a 404 (2026-09-30, developer's order) ─────────────────────────────────
 *
 * The storefront's server getters used to catch ANY error from core and return null, and null meant
 * notFound(). So a 429 from core's rate limit, a 5xx or a timeout answered "404, no such product" —
 * to shoppers and to Google, which drops a 404 from its index. Measured locally: 368–460 of 842
 * sitemap URLs 404'd under load, every one a real product.
 *
 * Now `Frontend-next/src/lib/coreRead.js` decides: 404/422 from core is a real miss; anything else is
 * retried once, then served from the process's last good copy, and only then thrown — the page's
 * error boundary answers a 5xx. The storefront has no test runner, so this suite holds it: the rule
 * itself runs in node, and the getters and pages are checked not to swallow failures again.
 * (The whole path, with a fake core in front of the real one, is `failure_check.sh`: 22/22.)
 */

function storefrontSource(string $relative): string
{
    $path = base_path('../Frontend-next/'.$relative);
    expect(is_file($path))->toBeTrue("missing storefront file {$relative}");

    return str_replace("\r\n", "\n", (string) file_get_contents($path));
}

function coreReadNode(): string
{
    return trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}

it('treats 404 and 422 as a miss, retries a failure once, serves the last good copy, and otherwise throws', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'coreread-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.DIRECTORY_SEPARATOR.'coreRead.mjs', storefrontSource('src/lib/coreRead.js'));
    file_put_contents($dir.DIRECTORY_SEPARATOR.'run.mjs', <<<'JS'
        import { coreRead, CoreUnavailableError, __resetCoreRead } from './coreRead.mjs'
        const fail = (status) => Object.assign(new Error('http ' + status), { response: { status } })
        const timeout = () => Object.assign(new Error('timeout'), { code: 'ECONNABORTED' })
        const seq = (...steps) => { let i = 0; const calls = { n: 0 }; const fn = async () => { calls.n++; const s = steps[Math.min(i++, steps.length - 1)]; if (s instanceof Error) throw s; return s }; fn.calls = calls; return fn }
        const out = {}
        const outcome = async (key, fn) => { try { return { value: await coreRead(key, fn), calls: fn.calls.n } } catch (e) { return { threw: e instanceof CoreUnavailableError ? 'CoreUnavailableError' : e.message, calls: fn.calls.n } } }
        out.miss404 = await outcome('a', seq(fail(404)))
        out.miss422 = await outcome('b', seq(fail(422)))
        out.rateLimited = await outcome('c', seq(fail(429), fail(429)))
        out.retrySaves = await outcome('d', seq(fail(503), 'fresh'))
        out.timeoutThrows = await outcome('e', seq(timeout(), timeout()))
        out.forbiddenIsNotAMiss = await outcome('f', seq(fail(403), fail(403)))
        await outcome('g', seq('good'))
        out.staleCopy = await outcome('g', seq(fail(500), fail(500)))
        await outcome('h', seq('was here'))
        await outcome('h', seq(fail(404)))
        out.goneStaysGone = await outcome('h', seq(fail(500), fail(500)))
        await outcome('i', seq(fail(429), fail(429)))
        out.heldFailure = await outcome('i', seq('would succeed'))
        __resetCoreRead()
        console.log(JSON.stringify(out))
        JS);
    $raw = trim((string) shell_exec('node '.escapeshellarg($dir.DIRECTORY_SEPARATOR.'run.mjs').' 2>&1'));
    array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
    rmdir($dir);

    // coreRead logs a warning when it serves a last good copy; the verdicts are the last line.
    $lines = preg_split('/\R/', $raw) ?: [];
    $r = json_decode((string) end($lines), true);
    expect($r)->toBeArray("node said: {$raw}");
    expect($r)->toBe([
        'miss404' => ['value' => null, 'calls' => 1],            // core looked: no such thing → the page may 404
        'miss422' => ['value' => null, 'calls' => 1],
        'rateLimited' => ['threw' => 'CoreUnavailableError', 'calls' => 2],   // one retry, then the error page — never null
        'retrySaves' => ['value' => 'fresh', 'calls' => 2],
        'timeoutThrows' => ['threw' => 'CoreUnavailableError', 'calls' => 2],
        'forbiddenIsNotAMiss' => ['threw' => 'CoreUnavailableError', 'calls' => 2],
        'staleCopy' => ['value' => 'good', 'calls' => 2],        // the last good copy beats an error page
        'goneStaysGone' => ['threw' => 'CoreUnavailableError', 'calls' => 2], // a 404 erases the copy
        'heldFailure' => ['threw' => 'CoreUnavailableError', 'calls' => 0],   // within 5 s: no new request
    ]);
})->skip(fn () => coreReadNode() === '', 'node is not installed here');

it('never lets a server getter turn a core failure into null or an empty list', function () {
    $catalog = storefrontSource('src/lib/serverCatalog.js');
    $blogs = storefrontSource('src/lib/serverBlogs.js');
    $bodies = [];
    foreach ([['getServerProduct', $catalog], ['fetchProductByName', $catalog], ['getServerBlog', $blogs], ['getServerBlogs', $blogs]] as [$name, $src]) {
        $at = strpos($src, "export const {$name} = cache(");
        expect($at)->not->toBeFalse("{$name} not found");
        $end = strpos($src, "\n})", (int) $at);
        $bodies[$name] = substr($src, (int) $at, (int) $end - (int) $at);
    }
    foreach ($bodies as $name => $body) {
        expect(str_contains($body, 'coreRead('))->toBeTrue("{$name} must read through coreRead")
            ->and(preg_match('/catch\s*(\([^)]*\))?\s*\{[^}]*return (null|\[\])/', $body))->toBe(0, "{$name} swallows a core failure again");
    }
});

it('makes the product, id, offer and facet pages 404 only on a definite miss', function () {
    $product = storefrontSource('app/(main)/product/[slug]/page.jsx');
    $id = storefrontSource('app/(main)/products/[id]/page.jsx');
    $offer = storefrontSource('app/(main)/offer/[slug]/page.jsx');
    $facet = storefrontSource('src/lib/facetListing.jsx');

    expect(str_contains($product, 'if (failure) throw failure'))->toBeTrue('product page: a failed slug lookup must not become a 404')
        ->and(str_contains($id, 'if (!product && failure) throw failure'))->toBeTrue('id route: a failed lookup must not become a 404')
        ->and(preg_match('/async function resolveOffer[\s\S]*?\n\}/', $offer, $m))->toBe(1);
    expect(preg_match('/catch\s*\{[^}]*return null/', $m[0] ?? ''))->toBe(0, 'offer page: a failed offers read must not become a 404');
    expect(preg_match('/try\s*\{\s*tables = \(await getServerTables\(\)\)/', $facet))->toBe(0, 'facet pages: unreachable tables must not become a 404');
});
