<?php

use App\Elegance\EleganceContent;
use App\Http\Controllers\EleganceController;
use App\Http\Middleware\EleganceHeaders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\T;

use function Pest\Laravel\call;
use function Pest\Laravel\get;
use function Pest\Laravel\withCookie;

/*
 * THE ELEGANCE COMPANY PAGE (eleganceeg.com /, /ar; 2026-11-21) - every security requirement of Michael's brief,
 * section 4, held by a test: static, no cookie, the exact headers, no dashboard hint, no third party, no input read,
 * no database touched, every link plain and to a page of ours; and the dashboard answering exactly as before.
 * The plan: core/docs/elegance/ELEGANCE_LANDING_PLAN_20261121.md.
 */

const ELEGANCE_PAGES = ['/' => 'en', '/ar' => 'ar'];
// words that would point at the dashboard or the API: none may appear on the page, in robots.txt or in the sitemap
const ELEGANCE_FORBIDDEN = '/manage|login|log in|sign in|sign-in|dashboard|\/api\b|admin|password/i';

function eleganceHtml(string $path): string
{
    return T::str(get($path)->assertOk()->getContent());
}

it('serves the company page in English at / and in Arabic at /ar, right to left, with its head and structured data', function () {
    foreach (ELEGANCE_PAGES as $path => $locale) {
        $html = eleganceHtml($path);
        expect($html)->toContain('<html lang="'.$locale.'" dir="'.($locale === 'ar' ? 'rtl' : 'ltr').'">')
            ->toContain('<link rel="canonical" href="'.($locale === 'ar' ? 'https://eleganceeg.com/ar' : 'https://eleganceeg.com/').'">')
            ->toContain('hreflang="en" href="https://eleganceeg.com/"')->toContain('hreflang="ar" href="https://eleganceeg.com/ar"')
            ->toContain('hreflang="x-default" href="https://eleganceeg.com/"')
            ->toContain('<meta property="og:image" content="https://eleganceeg.com/elegance/og-'.$locale.'.jpg">')
            ->toContain('90 El Thawra Street')->and($html)->not->toContain('{{');
        if ($locale === 'ar') {
            expect($html)->toContain('٩٠ شارع الثورة، الدور الأرضي')->toContain('إليجانس');
        }
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $m);
        $ld = T::arr(json_decode(T::str($m[1] ?? ''), true));
        expect($ld['@type'] ?? null)->toBe('Organization')->and($ld['name'] ?? null)->toBe('Elegance')
            ->and($ld['url'] ?? null)->toBe('https://eleganceeg.com/')->and($ld['logo'] ?? null)->toBe('https://eleganceeg.com/elegance/logo.png')
            ->and($ld['telephone'] ?? null)->toBe('+20 120 011 3375')->and($ld['email'] ?? null)->toBe('info@eleganceeg.com')
            ->and(T::arr($ld['address'] ?? null)['streetAddress'] ?? null)->toBe('90 El Thawra Street, ground floor')
            ->and(array_column(T::arr($ld['subOrganization'] ?? null), 'url'))->toBe(['https://watchizereg.com', 'https://www.brandfashionegy.com']);
    }
});

it('sets no cookie at all - no session, XSRF or remember cookie - on GET or HEAD, even when a session cookie is sent in', function () {
    $name = T::str(config('session.cookie'));
    foreach (array_keys(ELEGANCE_PAGES) as $path) {
        foreach (['GET', 'HEAD'] as $method) {
            $r = call($method, $path);
            expect($r->headers->has('Set-Cookie'))->toBeFalse("{$method} {$path} set a cookie")->and($r->headers->getCookies())->toBe([]);
        }
        $r = withCookie($name, 'a-stale-session-value')->withCookie('XSRF-TOKEN', 'x')->get($path);
        expect($r->headers->getCookies())->toBe([]);
    }
});

it('sends exactly the security headers of the brief, no HSTS (the host does not send it) and no X-Powered-By', function () {
    foreach (array_keys(ELEGANCE_PAGES) as $path) {
        $r = get($path);
        expect($r->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'")
            ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($r->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($r->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($r->headers->get('Permissions-Policy'))->toBe('camera=(), microphone=(), geolocation=()')
            ->and($r->headers->has('Strict-Transport-Security'))->toBeFalse()
            ->and($r->headers->has('X-Powered-By'))->toBeFalse();
        expect(T::str($r->headers->get('Content-Security-Policy')))->not->toContain('unsafe');
    }
});

it('reads nothing from the request and touches no database: a query string changes nothing, and not one query runs', function () {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $plain = eleganceHtml('/');
    $withQuery = T::str(get('/?lang=ar&locale=ar&x=%3Cscript%3Ealert(1)%3C%2Fscript%3E')->assertOk()->getContent());
    expect($withQuery)->toBe($plain)->and($withQuery)->not->toContain('alert(1)');
    eleganceHtml('/ar');
    expect($queries)->toBe(0);
});

it('has no form, input, script, iframe, inline style or event handler, and nothing loaded from another host', function () {
    foreach (array_keys(ELEGANCE_PAGES) as $path) {
        $html = eleganceHtml($path);
        // the only <script> is the JSON-LD (data, never executed under the CSP)
        expect(preg_match_all('#<script\b#i', $html))->toBe(1)->and($html)->toContain('<script type="application/ld+json">')
            ->and(preg_match('#<(form|input|textarea|select|button|iframe|object|embed)\b#i', $html))->toBe(0)
            ->and(preg_match('#\sstyle=#i', $html))->toBe(0)
            ->and(preg_match('#\son[a-z]+=#i', $html))->toBe(0);
        // every asset (src, srcset, stylesheet, icon) is on this host, under /elegance/
        preg_match_all('#\s(?:src|srcset)="([^"]+)"#', $html, $sources);
        foreach ($sources[1] as $value) {
            foreach (explode(',', $value) as $part) {
                expect(trim(explode(' ', trim($part))[0]))->toStartWith('/elegance/');
            }
        }
        preg_match_all('#<link rel="(?:stylesheet|icon|apple-touch-icon)" href="([^"]+)"#', $html, $links);
        foreach ($links[1] as $href) {
            expect($href)->toStartWith('/elegance/');
        }
        // fonts and images in the stylesheet are its own
        $css = T::str(file_get_contents(public_path('elegance/elegance.css')));
        expect(preg_match('#url\((?![\'"]?fonts/)#', $css))->toBe(0)->and($css)->not->toContain('@import')->and($css)->not->toContain('http');
    }
});

it('never hints at the dashboard: not in the page, its structured data, robots.txt or the sitemap; robots.txt does not even name it', function () {
    foreach (array_keys(ELEGANCE_PAGES) as $path) {
        expect(preg_match(ELEGANCE_FORBIDDEN, eleganceHtml($path)))->toBe(0);
    }
    $robots = T::str(file_get_contents(public_path('elegance/robots.txt')));
    $sitemap = T::str(file_get_contents(public_path('elegance/sitemap.xml')));
    expect(preg_match(ELEGANCE_FORBIDDEN, $robots))->toBe(0)->and(preg_match(ELEGANCE_FORBIDDEN, $sitemap))->toBe(0)
        ->and($robots)->toBe("User-agent: *\nAllow: /$\nAllow: /ar$\nAllow: /elegance/\nDisallow: /\n\nSitemap: https://eleganceeg.com/elegance/sitemap.xml\n")
        ->and($sitemap)->toContain('<loc>https://eleganceeg.com/</loc>')->toContain('<loc>https://eleganceeg.com/ar</loc>');
});

it('links only pages of ours: every outbound link is a plain <a rel="noopener noreferrer"> from the checked link table, in the page\'s language', function () {
    $table = EleganceContent::links();
    foreach (ELEGANCE_PAGES as $path => $locale) {
        $html = eleganceHtml($path);
        preg_match_all('#<a\b([^>]*)>#', $html, $anchors);
        $outbound = [];
        foreach ($anchors[1] as $attrs) {
            preg_match('#href="([^"]+)"#', $attrs, $h);
            $href = html_entity_decode($h[1] ?? '');
            expect($attrs)->not->toContain('target=')->and($attrs)->not->toContain('onclick');
            if (preg_match('#^https?://#', $href) === 1) {
                expect($attrs)->toContain('rel="noopener noreferrer"');
                $outbound[] = $href;
            } else {
                // in-page anchors, the language switch, tel: and mailto: only
                expect(preg_match('#^(\#[a-z]+|/|/ar|tel:\+201200113375|mailto:info@eleganceeg\.com)$#', $href))->toBe(1, "unexpected link {$href}");
            }
        }
        $allowed = array_filter(array_column($table, $locale));
        foreach (array_unique($outbound) as $href) {
            expect(in_array($href, $allowed, true))->toBeTrue("{$href} is not in the checked link table");
        }
        foreach ($table as $row) {
            if ($row[$locale] !== null && $row['group'] !== 'map') {
                expect(in_array($row[$locale], $outbound, true))->toBeTrue("{$row['key']} is in the table but not on the {$locale} page");
            }
        }
        // the Arabic page links the shops' Arabic pages; no link points back at the dashboard's host
        if ($locale === 'ar') {
            foreach ($outbound as $href) {
                if (str_contains($href, 'watchizereg.com') || str_contains($href, 'brandfashionegy.com')) {
                    expect($href)->toMatch('#^https://(www\.)?(watchizereg|brandfashionegy)\.com/ar(/|$)#');
                }
            }
        }
        // no anchor leads back to this host by absolute URL (the canonical and hreflang <link>s do, on purpose)
        expect(preg_match('#<a\b[^>]*href="https?://(www\.)?eleganceeg\.com#', $html))->toBe(0);
    }
    // the two exceptions to the rule (the other shop's page), and the one brand left unlinked, as checked on 21 Nov
    expect(array_column(array_filter($table, fn (array $r): bool => $r['fallback']), 'key'))->toBe(['seiko', 'tommy-hilfiger-wallets'])
        ->and(T::arr(collect($table)->firstWhere('key', 'casio')))->toMatchArray(['shop' => null, 'en' => null, 'ar' => null]);
});

it('changes only GET / and adds GET /ar: both without the session group, with the page headers; no new non-GET route', function () {
    $routes = collect(Route::getRoutes()->getRoutes());
    foreach (['/' => 'elegance', 'ar' => 'elegance.ar'] as $uri => $name) {
        $route = $routes->first(fn ($r) => $r->uri() === $uri);
        if (! $route instanceof Illuminate\Routing\Route) {
            throw new RuntimeException("no route {$uri}");
        }
        expect($route->methods())->toBe(['GET', 'HEAD'])->and($route->getName())->toBe($name)
            ->and($route->getActionName())->toBe(EleganceController::class);
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain(EleganceHeaders::class)
            ->and($middleware)->not->toContain(Illuminate\Session\Middleware\StartSession::class)
            ->and($middleware)->not->toContain(Illuminate\Cookie\Middleware\EncryptCookies::class);
        expect($routes->filter(fn ($r) => $r->uri() === $uri)->count())->toBe(1);
    }
    expect(Route::has('dashboard'))->toBeFalse();
});

it('leaves the dashboard as it was: /manage still sends a guest to the sign-in with its own session cookie', function () {
    $r = get('/manage');
    $r->assertRedirect(route('manage.login'));
    $names = array_map(fn ($c) => $c->getName(), $r->headers->getCookies());
    expect($names)->toContain(T::str(config('session.cookie')))
        ->and($r->headers->get('Content-Security-Policy'))->toBeNull()
        ->and($r->headers->get('X-Frame-Options'))->toBeNull();
    get('/manage/login')->assertOk();
});

it('answers a missing page on this host with the existing 404 page, no trace, no page headers', function () {
    $r = get('/no-such-page-on-eleganceeg');
    $r->assertNotFound();
    $body = T::str($r->getContent());
    expect($body)->not->toContain('Stack trace')->and($body)->not->toContain('vendor/laravel')
        ->and($r->headers->get('Content-Security-Policy'))->toBeNull();
});
