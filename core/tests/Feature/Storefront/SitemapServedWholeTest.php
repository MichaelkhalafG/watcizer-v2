<?php

use function Pest\Laravel\get;

/*
 * ── The storefront serves every sitemap whole (2026-10-01) ───────────────────────────────────────
 *
 * The storefront used to PROXY /sitemap.xml and /sitemaps/{en,ar}.xml to core with next.config.js
 * rewrites. Measured on production from a browser, those answered 200 with `content-encoding: br`
 * and `content-length: 0` — /sitemap.xml every time, en.xml and ar.xml intermittently — while a curl
 * without Accept-Encoding always got the full file, so no check caught it. /sitemaps/index.xml,
 * which core's own index and Search Console point at, was a 404: nothing routed it.
 *
 * Now `Frontend-next/app/sitemaps/[file]/route.js` fetches core itself and answers with core's bytes,
 * and never passes on a 200 without a <loc>. This suite holds that: the handler runs in node against
 * a stand-in core serving core's REAL sitemaps (plain, gzip, brotli, chunked), the routing is checked
 * to reach the handler for every path a crawler is given, and core's own sitemaps must list pages.
 */

const SITEMAP_FILES = ['index.xml', 'en.xml', 'ar.xml'];

function sitemapStorefrontSource(string $relative): string
{
    $path = base_path('../Frontend-next/'.$relative);
    expect(is_file($path))->toBeTrue("missing storefront file {$relative}");

    return str_replace("\r\n", "\n", (string) file_get_contents($path));
}

function sitemapNode(): string
{
    return trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}

it('serves every sitemap from core with at least one <loc>', function () {
    $empty = [];
    foreach (SITEMAP_FILES as $file) {
        $xml = (string) get("/sitemaps/{$file}")->assertOk()->getContent();
        if (substr_count($xml, '<loc>') === 0) {
            $empty[] = $file;
        }
    }
    expect($empty)->toBe([]);
});

it('answers every sitemap byte-for-byte as core does, however core encodes it, and never an empty 200', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sitemap-'.bin2hex(random_bytes(4));
    mkdir($dir);
    foreach (SITEMAP_FILES as $file) {
        file_put_contents($dir.DIRECTORY_SEPARATOR.$file, (string) get("/sitemaps/{$file}")->assertOk()->getContent());
    }
    file_put_contents($dir.DIRECTORY_SEPARATOR.'route.mjs', sitemapStorefrontSource('app/sitemaps/[file]/route.js'));
    file_put_contents($dir.DIRECTORY_SEPARATOR.'run.mjs', <<<'JS'
        import http from 'node:http'
        import zlib from 'node:zlib'
        import fs from 'node:fs'
        import path from 'node:path'
        import { fileURLToPath } from 'node:url'
        const dir = path.dirname(fileURLToPath(import.meta.url))
        const files = ['index.xml', 'en.xml', 'ar.xml']
        const real = Object.fromEntries(files.map((f) => [f, fs.readFileSync(path.join(dir, f))]))
        // A stand-in core: `mode` decides how the next reply is encoded, or what goes wrong.
        let mode = 'plain'
        const server = http.createServer((req, res) => {
          const file = (req.url.match(/^\/sitemaps\/([a-z]+\.xml)$/) || [])[1]
          if (mode === 'error') { res.writeHead(500); return res.end('boom') }
          if (!file || !real[file]) { res.writeHead(404); return res.end('nope') }
          if (mode === 'empty') { res.writeHead(200, { 'Content-Type': 'application/xml; charset=UTF-8', 'Content-Length': 0 }); return res.end() }
          let body = real[file]
          const h = { 'Content-Type': 'application/xml; charset=UTF-8' }
          if (mode === 'br') { body = zlib.brotliCompressSync(body); h['Content-Encoding'] = 'br' }
          if (mode === 'gzip' || mode === 'chunked') { body = zlib.gzipSync(body); h['Content-Encoding'] = 'gzip' }
          if (mode !== 'chunked') h['Content-Length'] = body.length
          res.writeHead(200, h)
          res.end(body)
        })
        await new Promise((r) => server.listen(0, '127.0.0.1', r))
        process.env.LARAVEL_ORIGIN = `http://127.0.0.1:${server.address().port}/`
        const { GET } = await import('./route.mjs')
        const ask = async (file) => {
          // What a crawler sends; the handler must not pass it on.
          const request = new Request(`https://watchizereg.com/sitemaps/${file}`, { headers: { 'Accept-Encoding': 'gzip, deflate, br, zstd' } })
          const res = await GET(request, { params: Promise.resolve({ file }) })
          return { status: res.status, type: res.headers.get('content-type'), encoding: res.headers.get('content-encoding'), body: Buffer.from(await res.arrayBuffer()) }
        }
        const out = {}
        for (const m of ['plain', 'gzip', 'br', 'chunked']) {
          mode = m
          for (const f of files) {
            const r = await ask(f)
            out[`${m} ${f}`] = [r.status, r.type, r.encoding, r.body.equals(real[f]) ? 'same bytes' : `differs (${r.body.length} vs ${real[f].length})`]
          }
        }
        mode = 'empty'; out['empty 200'] = (await ask('ar.xml')).status
        mode = 'error'; out['core 500'] = (await ask('en.xml')).status
        mode = 'plain'; out['unknown file'] = (await ask('fr.xml')).status
        server.close()
        out['core down'] = (await ask('index.xml')).status
        console.log(JSON.stringify(out))
        JS);
    $raw = trim((string) shell_exec('node '.escapeshellarg($dir.DIRECTORY_SEPARATOR.'run.mjs').' 2>&1'));
    array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
    rmdir($dir);

    // The handler logs each refusal; the verdicts are the last line.
    $lines = preg_split('/\R/', $raw) ?: [];
    $r = json_decode((string) end($lines), true);
    expect($r)->toBeArray("node said: {$raw}");
    $whole = [200, 'application/xml; charset=UTF-8', null, 'same bytes'];
    $expected = [];
    foreach (['plain', 'gzip', 'br', 'chunked'] as $mode) {
        foreach (SITEMAP_FILES as $file) {
            $expected["{$mode} {$file}"] = $whole;
        }
    }
    expect($r)->toBe([...$expected, 'empty 200' => 503, 'core 500' => 503, 'unknown file' => 404, 'core down' => 503]);
})->skip(fn () => sitemapNode() === '', 'node is not installed here');

it('routes every sitemap a crawler is given to that handler, and none of them through a proxy', function () {
    $config = sitemapStorefrontSource('next.config.js');
    $middleware = sitemapStorefrontSource('middleware.js');
    $robots = sitemapStorefrontSource('public/robots.txt');

    // No rewrite sends a sitemap path to core: the proxy is what emptied them.
    $wrong = [];
    preg_match_all("/\{\s*source:\s*'([^']+)',\s*destination:\s*([^}]+)\}/", $config, $rules, PREG_SET_ORDER);
    foreach ($rules as [, $source, $destination]) {
        if (str_contains($source, 'sitemap') && str_contains($destination, 'LARAVEL_ORIGIN')) {
            $wrong[] = "{$source} is proxied to core";
        }
    }
    expect(str_contains($config, "{ source: '/sitemap.xml', destination: '/sitemaps/index.xml' }"))->toBeTrue('/sitemap.xml must rewrite internally onto /sitemaps/index.xml');

    // The middleware leaves every sitemap path alone (its matcher skips paths with an extension).
    expect(preg_match("/matcher:\s*\['([^']+)'\]/", $middleware, $m))->toBe(1);
    $matcher = '#^'.str_replace(['\\\\', '#'], ['\\', '\\#'], $m[1] ?? '').'$#';
    expect(preg_match($matcher, '/listing'))->toBe(1, 'the matcher was not read right: it must match a page');
    $paths = ['/sitemap.xml', ...array_map(fn (string $f): string => "/sitemaps/{$f}", SITEMAP_FILES)];
    foreach ($paths as $path) {
        if (preg_match($matcher, $path) === 1) {
            $wrong[] = "the middleware intercepts {$path}";
        }
    }

    // Every sitemap core's index names, and the one robots.txt names, is one the handler serves.
    $handler = sitemapStorefrontSource('app/sitemaps/[file]/route.js');
    expect(preg_match("/const FILES = new Set\(\[([^\]]+)\]\)/", $handler, $f))->toBe(1);
    $served = array_map(fn (string $s): string => trim($s, " '"), explode(',', $f[1] ?? ''));
    expect($served)->toBe(SITEMAP_FILES);
    preg_match_all('#<loc>https://watchizereg\.com/sitemaps/([a-z]+\.xml)</loc>#', (string) get('/sitemaps/index.xml')->getContent(), $named);
    expect($named[1])->not->toBe([]);
    foreach ($named[1] as $file) {
        if (! in_array($file, $served, true)) {
            $wrong[] = "core's index names /sitemaps/{$file}, which the storefront does not serve";
        }
    }
    expect(preg_match_all('/^Sitemap:\s*(\S+)/m', $robots, $s))->toBeGreaterThan(0);
    foreach ($s[1] as $url) {
        if (! in_array($url, ['https://watchizereg.com/sitemap.xml', 'https://watchizereg.com/sitemaps/index.xml'], true)) {
            $wrong[] = "robots.txt names {$url}, which is not a served index";
        }
    }
    expect($wrong)->toBe([]);
});
