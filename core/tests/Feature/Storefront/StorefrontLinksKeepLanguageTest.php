<?php

/*
 * ── Every internal link keeps the language of the page it is on (D7, developer, 2026-09-30) ────────
 *
 * "Arabic is /ar/… and English is the bare URL, and every link inside a page keeps its own language."
 * A crawler carries no cookie: a bare link out of an /ar page took it to the English site, so Google
 * saw one Arabic page with an English site behind it. Measured before the fix: 250 of 250 internal
 * links on ten Arabic pages were bare; after: 0 (ar_links_check.py), and every click, tap and search
 * stays in its language (ar_nav_check2.mjs, 19/19).
 *
 * The rule lives in two wrappers — `src/Components/LocaleLink.jsx` (next/link) and
 * `src/Hooks/useLocaleRouter.js` (next/navigation's router) — over `localizeHref`. This guards that
 * no page goes around them, and that the rule itself holds. The storefront has no test runner.
 */

/** @return array<string, string> storefront-relative path => source (LF) */
function linkSources(): array
{
    $root = dirname(base_path()).DIRECTORY_SEPARATOR.'Frontend-next';
    $out = [];
    foreach (['src', 'app'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof SplFileInfo && preg_match('/\.(jsx?|mjs)$/', $file->getFilename())) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $out[$rel] = str_replace("\r\n", "\n", (string) file_get_contents($file->getPathname()));
            }
        }
    }

    return $out;
}

it('sends every link and every router navigation through the language-keeping wrappers', function () {
    $direct = [];
    foreach (linkSources() as $rel => $src) {
        if ($rel !== 'src/Components/LocaleLink.jsx' && preg_match("/from 'next\\/link'/", $src)) {
            $direct[] = "{$rel}: imports next/link (use @/src/Components/LocaleLink)";
        }
        // The language switch is the one place allowed to cross languages; the wrapper wraps the original.
        if (! in_array($rel, ['src/Hooks/useLocaleRouter.js', 'src/Hooks/useSwitchLanguage.js'], true)
            && preg_match("/import \\{[^}]*\\buseRouter\\b[^}]*\\} from 'next\\/navigation'/", $src)) {
            $direct[] = "{$rel}: takes useRouter from next/navigation (use @/src/Hooks/useLocaleRouter)";
        }
        if (preg_match_all('/<a\b[^>]*\bhref="\/(?!\/)[^"]*"/', $src, $m)) {
            foreach ($m[0] as $tag) {
                $direct[] = "{$rel}: a literal internal <a href> ({$tag}) — use <Link> or localePath()";
            }
        }
    }

    expect($direct)->toBe([]);
});

it('moves a path into the page\'s language, idempotently, and leaves everything that is not a page alone', function () {
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'localehref-'.bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents($dir.DIRECTORY_SEPARATOR.'localePath.mjs', linkSources()['src/utils/localePath.js']);
    file_put_contents($dir.DIRECTORY_SEPARATOR.'run.mjs', <<<'JS'
        import { localizeHref as L } from './localePath.mjs'
        console.log(JSON.stringify({
          arHome: L('/', 'ar'), arPage: L('/listing?brand=rolex', 'ar'), arHash: L('/product/x#reviews', 'ar'),
          alreadyAr: L('/ar/product/x', 'ar'), arRoot: L('/ar', 'ar'), enStrips: L('/ar/listing?q=a', 'en'),
          enBare: L('/cart', 'en'), external: L('https://wa.me/1', 'ar'), protocolRelative: L('//cdn.x/y', 'ar'),
          anchor: L('#top', 'ar'), file: L('/logo.svg', 'ar'), api: L('/api/x', 'ar'), next: L('/_next/static/a.js', 'ar'),
          object: L({ pathname: '/listing', query: { q: 'a' } }, 'ar'),
        }))
        JS);
    $raw = trim((string) shell_exec('node '.escapeshellarg($dir.DIRECTORY_SEPARATOR.'run.mjs').' 2>&1'));
    array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
    rmdir($dir);

    expect(json_decode($raw, true))->toBe([
        'arHome' => '/ar', 'arPage' => '/ar/listing?brand=rolex', 'arHash' => '/ar/product/x#reviews',
        'alreadyAr' => '/ar/product/x', 'arRoot' => '/ar', 'enStrips' => '/listing?q=a',
        'enBare' => '/cart', 'external' => 'https://wa.me/1', 'protocolRelative' => '//cdn.x/y',
        'anchor' => '#top', 'file' => '/logo.svg', 'api' => '/api/x', 'next' => '/_next/static/a.js',
        'object' => ['pathname' => '/ar/listing', 'query' => ['q' => 'a']],
    ], "node said: {$raw}");
})->skip(fn () => trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null')) === '', 'node is not installed here');
