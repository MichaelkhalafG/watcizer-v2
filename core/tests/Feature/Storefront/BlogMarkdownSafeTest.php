<?php

use Tests\Support\T;

/*
 * ── An article's Markdown is safe by construction, and the preview is the page (2026-10-02) ─────────
 *
 * The blog editor stores Markdown (react-markdown 10.1, developer-approved). It is rendered by TWO
 * files with the same rules: `Frontend-next/src/lib/markdown.js` on the shop and
 * `core/resources/js/lib/markdown.ts` in the dashboard's preview. This suite renders a corpus of
 * attacks through BOTH, in Node, to static HTML, and requires:
 *  - nothing executable comes out — no script, iframe, style, svg, object or embed element, no `on…=`
 *    attribute, no javascript:/data:/vbscript:/protocol-relative address, no image from another host;
 *  - what a writer typed as HTML is SHOWN as text, not dropped silently (they can see what they did);
 *  - ordinary Markdown — headings, lists, bold, links, our own uploaded images — comes out as expected;
 *  - the two files produce byte-identical HTML for every input, so the preview cannot drift from the shop.
 */

const MD_ASSET_BASE = 'https://api.watchizereg.com';

/** @var array<string, string> */
const MD_HOSTILE = [
    'script' => '<script>alert(1)</script>',
    'img onerror' => '<img src=x onerror=alert(1)>',
    'iframe' => '<iframe src="https://evil.example"></iframe>',
    'style' => '<style>body{display:none}</style>',
    'svg onload' => '<svg onload=alert(1)></svg>',
    'raw anchor' => '<a href="javascript:alert(1)">raw</a>',
    'object' => '<object data="https://evil.example/x.swf"></object>',
    'javascript link' => '[click](javascript:alert(1))',
    'mixed-case javascript' => '[click](JaVaScRiPt:alert(1))',
    'spaced javascript' => '[click](  javascript:alert(1))',
    'entity-split javascript' => '[click](java&#x09;script:alert(1))',
    'data link' => '[click](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
    'vbscript link' => '[click](vbscript:msgbox(1))',
    'protocol-relative link' => '[click](//evil.example/x)',
    'javascript autolink' => '<javascript:alert(1)>',
    'reference link' => "[click][x]\n\n[x]: javascript:alert(1)",
    'title attribute' => '[click](https://example.com "t\" onmouseover=\"alert(1)")',
    'external image' => '![pixel](https://evil.example/pixel.gif)',
    'data image' => '![x](data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+)',
    'traversal image' => '![x](/Uploads_Images/../../.env)',
    'image with handler' => '![x](/Uploads_Images/a.webp" onerror="alert(1))',
    'h1' => '# Big heading',
];

/** @var array<string, string> */
const MD_ORDINARY = [
    'article' => "## Choosing a strap\n\nA **leather** strap is *warm*; steel is not.\n\n- one\n- two\n\n1. first\n2. second\n\n> A quote.\n\nSee [the listing](/listing), [our Instagram](https://www.instagram.com/watchizer_eg/), [mail us](mailto:watchizer303@gmail.com) or [call](tel:+201551096234).\n\n![A watch](/Uploads_Images/Banner/1789_a.webp)",
    'arabic' => "## اختيار السوار\n\nالسوار **الجلدي** دافئ.\n\n- أول\n- ثاني",
];

/** @return array{shop: array<string, string>, dashboard: array<string, string>} */
function mdRender(): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'md-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $root = str_replace('\\', '/', realpath(base_path('..')) ?: '');
    $inputs = json_encode(['hostile' => MD_HOSTILE, 'ordinary' => MD_ORDINARY, 'base' => MD_ASSET_BASE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    file_put_contents($dir.DIRECTORY_SEPARATOR.'in.json', $inputs);
    file_put_contents($dir.DIRECTORY_SEPARATOR.'run.mjs', <<<JS
        import fs from 'node:fs'
        import { createRequire } from 'node:module'
        import { pathToFileURL } from 'node:url'
        const root = '{$root}'
        const input = JSON.parse(fs.readFileSync(new URL('./in.json', import.meta.url), 'utf8'))
        const apps = {
          shop: { file: root + '/Frontend-next/src/lib/markdown.js', from: root + '/Frontend-next/package.json' },
          dashboard: { file: root + '/core/resources/js/lib/markdown.ts', from: root + '/core/package.json' },
        }
        const out = {}
        for (const [name, app] of Object.entries(apps)) {
          const req = createRequire(app.from)
          const React = req('react')
          const { renderToStaticMarkup } = req('react-dom/server')
          const { MarkdownBody } = await import(pathToFileURL(app.file).href)
          out[name] = {}
          for (const group of ['hostile', 'ordinary']) {
            for (const [k, md] of Object.entries(input[group])) {
              out[name][group + ':' + k] = renderToStaticMarkup(React.createElement(MarkdownBody, { text: md, assetBase: input.base }))
            }
          }
        }
        console.log(JSON.stringify(out))
        JS);
    $raw = trim((string) shell_exec('node --no-warnings '.escapeshellarg($dir.DIRECTORY_SEPARATOR.'run.mjs').' 2>&1'));
    array_map('unlink', glob($dir.DIRECTORY_SEPARATOR.'*') ?: []);
    rmdir($dir);
    $lines = preg_split('/\R/', $raw) ?: [];
    $r = json_decode((string) end($lines), true);
    expect($r)->toBeArray("node said: {$raw}");
    $out = ['shop' => [], 'dashboard' => []];
    foreach (['shop', 'dashboard'] as $app) {
        foreach (T::arr(T::arr($r)[$app] ?? []) as $case => $html) {
            $out[$app][(string) $case] = T::str($html);
        }
    }

    return $out;
}

function mdNode(): string
{
    return trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
}

it('renders every attack harmless, in the shop and in the preview alike', function () {
    $r = mdRender();
    $bad = [];
    foreach (['shop', 'dashboard'] as $app) {
        foreach (array_keys(MD_HOSTILE) as $case) {
            $html = $r[$app]['hostile:'.$case];
            foreach ([
                'an executable element' => '/<(script|iframe|style|svg|object|embed|form|input|base|meta|link)\b/i',
                'an event handler' => '/<[^>]*\son[a-z]+\s*=/i',
                'a dangerous address' => '/(href|src)="\s*(javascript|data|vbscript):/i',
                'a protocol-relative address' => '/(href|src)="\/\//i',
                'an image from another host' => '/<img[^>]+src="(?!https:\/\/api\.watchizereg\.com\/Uploads_Images\/)/i',
                'a second h1' => '/<h1\b/i',
            ] as $what => $pattern) {
                if (preg_match($pattern, $html) === 1) {
                    $bad[] = "{$app} / {$case}: {$what} in {$html}";
                }
            }
        }
    }
    expect($bad)->toBe([]);

    // Typed HTML is SHOWN as text — escaped — so the writer sees what they did; it is not dropped silently.
    expect($r['shop']['hostile:script'])->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($r['shop']['hostile:img onerror'])->toContain('&lt;img src=x onerror=alert(1)&gt;')
        // A refused link keeps its words, as text.
        ->and($r['shop']['hostile:javascript link'])->toBe('<div class="wz-article-body"><p><span>click</span></p></div>')
        ->and($r['shop']['hostile:external image'])->toBe('<div class="wz-article-body"><p></p></div>')
        ->and($r['shop']['hostile:h1'])->toBe('<div class="wz-article-body"><h2>Big heading</h2></div>');
})->skip(fn () => mdNode() === '', 'node is not installed here');

it('renders ordinary Markdown as expected, with our own images on our image host', function () {
    $html = mdRender()['shop']['ordinary:article'];
    foreach ([
        '<h2>Choosing a strap</h2>',
        '<strong>leather</strong>',
        '<em>warm</em>',
        '<ul>',
        '<ol>',
        '<blockquote>',
        '<a href="/listing">the listing</a>',
        '<a href="https://www.instagram.com/watchizer_eg/" target="_blank" rel="noopener noreferrer nofollow">our Instagram</a>',
        '<a href="mailto:watchizer303@gmail.com">mail us</a>',
        '<a href="tel:+201551096234">call</a>',
        '<img src="https://api.watchizereg.com/Uploads_Images/Banner/1789_a.webp" alt="A watch" loading="lazy" decoding="async"/>',
    ] as $fragment) {
        expect($html)->toContain($fragment);
    }
})->skip(fn () => mdNode() === '', 'node is not installed here');

it('previews exactly what the shop shows — the two renderers agree byte for byte', function () {
    $r = mdRender();
    $differ = [];
    foreach ($r['shop'] as $case => $html) {
        if (($r['dashboard'][$case] ?? null) !== $html) {
            $differ[] = $case;
        }
    }
    expect($differ)->toBe([])
        ->and(count($r['shop']))->toBe(count(MD_HOSTILE) + count(MD_ORDINARY));
})->skip(fn () => mdNode() === '', 'node is not installed here');
