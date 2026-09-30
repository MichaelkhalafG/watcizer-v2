<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CompatServices;
use App\Compat\LegacyLocaleNegotiator;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `/sitemap.xml` behaves like the legacy host: the bare path 302s to `/{locale}/sitemap.xml`
 * (mcamara's localization redirect, locale from Accept-Language), and the localised path
 * serves the XML. The Next.js rewrite already targets `/en/sitemap.xml` (next.config.js).
 */
class SitemapCompatController extends Controller
{
    public function __construct(private readonly CompatServices $compat) {}

    public function redirect(Request $request): RedirectResponse
    {
        /** @var array<string, string> $supported */
        $supported = config()->array('compat.locales');
        $locale = LegacyLocaleNegotiator::negotiate($request->header('Accept-Language'), $supported, config()->string('compat.default_locale'));

        return redirect()->to(url("/{$locale}/sitemap.xml"), 302, ['Vary' => 'Accept-Language']);
    }

    /** GET /sitemaps/index.xml — the shop's sitemap index: one sitemap per language (S-AR stage 3). */
    public function localeIndex(): Response
    {
        return response($this->compat->localeSitemap->index(), 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    /** GET /sitemaps/{locale}.xml — every shop page in that language, with alternates (S-AR stage 3). */
    public function localeUrlset(string $locale): Response
    {
        $xml = $this->compat->localeSitemap->urlset($locale);
        abort_if($xml === null, 404);

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function show(string $locale): Response
    {
        /** @var array<string, string> $supported */
        $supported = config()->array('compat.locales');
        abort_unless(isset($supported[$locale]), 404);

        return response($this->compat->sitemap->xml(), 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
