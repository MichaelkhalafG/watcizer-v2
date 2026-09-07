<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\Sitemaps;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SitemapController extends Controller
{
    public function index(Request $request, Sitemaps $sitemaps): Response
    {
        $base = rtrim($request->getSchemeAndHttpHost().'/'.dirname($request->path()), '/');

        return response($sitemaps->index($base), 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function chunk(string $locale, string $file, Sitemaps $sitemaps): Response
    {
        $xml = $sitemaps->chunk($locale, $file);
        if ($xml === null) {
            throw new NotFoundHttpException('Sitemap not found');
        }

        return response($xml, 200)->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
