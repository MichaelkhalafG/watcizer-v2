<?php

namespace App\Http\Controllers;

use App\Elegance\EleganceContent;
use Illuminate\Http\Response;

/**
 * The Elegance company page on eleganceeg.com: GET / (English) and GET /ar (Arabic), 2026-11-21.
 *
 * STATIC. The language comes from the route's own default, never from the request: no query string, header, cookie or
 * body is read, nothing is written, no database is touched. The routes run without the session group (no session, no
 * cookie, no CSRF token) and with EleganceHeaders (the page's security headers). The plan, with its security note:
 * core/docs/elegance/ELEGANCE_LANDING_PLAN_20261121.md.
 */
final class EleganceController
{
    public function __invoke(string $locale = 'en'): Response
    {
        return response()->view('elegance.home', ['c' => EleganceContent::page($locale)]);
    }
}
