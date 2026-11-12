<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Models\Storefront\StorefrontRedirect;
use App\Storefront\StorefrontContext;
use App\Transform\Row;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `GET /api/v2/{storefront}/redirect?path=/old/path/` — the reader of `storefront_redirects`
 * (2026-11-11, D-17). The table has been WRITTEN since wave 1 (slug changes, transform twins) and,
 * since the legacy import, holds the old WordPress URLs (`bf:legacy-redirects`); until this route
 * nothing read it. The storefront app's middleware asks it only for old-looking paths.
 *
 * The path is normalised the way the rows were stored: percent-decoded (WordPress slugs arrive
 * encoded), the query dropped, the trailing slash dropped, compared case-blind
 * ({@see StorefrontRedirect::hashPath()}). A hit counts in `hits` / `last_hit_at`, so the team can
 * see which old URLs still bring visitors. 404 when there is no row: the app then serves the path
 * as it is. Under /api/v2/, so the API host's allow-list (runbook §4.1.1) is unchanged; private and
 * uncached, because the map is re-run on switch day and must answer the new rows at once.
 */
final class RedirectController extends Controller
{
    public function show(Request $request, StorefrontContext $ctx): JsonResponse
    {
        $path = self::normalise((string) $request->query('path', ''));
        $headers = ['Cache-Control' => 'private, max-age=0'];
        if ($path === null) {
            return response()->json(['data' => null], 404, $headers);
        }

        $row = DB::table('storefront_redirects')
            ->where('storefront_id', $ctx->id())
            ->where('from_hash', StorefrontRedirect::hashPath($path))
            ->first(['id', 'to_path', 'status']);
        if ($row === null) {
            return response()->json(['data' => null], 404, $headers);
        }
        $r = Row::cast($row);
        DB::table('storefront_redirects')->where('id', Row::int($r, 'id'))->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => now()]);

        return response()->json(
            ['data' => ['to' => Row::str($r, 'to_path'), 'status' => Row::int($r, 'status')]],
            200,
            $headers,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** `/Product/x/?lang=ar` → `/Product/x`; null for anything that is not a path. */
    public static function normalise(string $raw): ?string
    {
        $path = rawurldecode((string) strtok($raw, '?#'));
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || mb_strlen($path) > 1000) {
            return null;
        }
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
