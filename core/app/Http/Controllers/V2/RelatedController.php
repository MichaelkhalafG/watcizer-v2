<?php

namespace App\Http\Controllers\V2;

use App\Compat\CompatRelated;
use App\Compat\CompatServices;
use App\Compat\CompatStorefront;
use App\Domain\Inventory\InventoryService;
use App\Http\Controllers\Controller;
use App\Storefront\ProductCards;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontContext;
use App\Support\Val;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/v2/{storefront}/products/{slug}/related — the PDP's two rails (spec §10.3): `addons` ("Goes with
 * this": in stock, a DIFFERENT family, same brand+gender then same gender) and `similar` ("Similar": the same
 * family, scored), as v2 card rows. The RULES are {@see CompatRelated}'s — the developer's of 2026-09-29,
 * unchanged, run for THIS storefront through a pinned {@see CompatServices}; nothing in compat is edited
 * (V2-READ-HALF step 1, 2026-10-17). `?exclude=ID,ID` is what the shopper's cart already holds.
 *
 * `similar` breaks score ties with a uniform shuffle (CompatRelated), so two calls may order ties differently —
 * the same as `catalog/related`; the set is stable.
 */
class RelatedController extends Controller
{
    public function show(string $slug, Request $request, StorefrontContext $ctx, ProductCards $cards): JsonResponse
    {
        $productId = DB::table('storefront_product')
            ->where('storefront_id', $ctx->id())->where('slug', $slug)->where('is_visible', 1)
            ->value('product_id');
        if (! is_numeric($productId)) {
            throw new NotFoundHttpException('Product not found');
        }
        $productId = (int) $productId;
        $exclude = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('exclude', ''))), fn (int $i) => $i > 0));

        $compat = new CompatServices(app(StorefrontCache::class), app(InventoryService::class), CompatStorefront::pinned($ctx->id()));
        $addons = $compat->related->addOns([$productId], $exclude, CompatRelated::ADDON_LIMIT);
        $similar = $compat->related->similar($productId, $exclude, CompatRelated::LIMIT);

        $byId = [];
        $ids = array_values(array_unique([...$addons, ...$similar]));
        if ($ids !== []) {
            $rows = $cards->base()->whereIn('sp.product_id', $ids)->select(ProductCards::columns())->get()->all();
            foreach ($cards->build($rows) as $card) {
                $byId[Val::int($card, 'id')] = $card;
            }
        }
        $pick = fn (array $list): array => array_values(array_filter(array_map(fn ($id) => is_int($id) ? ($byId[$id] ?? null) : null, $list)));

        return response()->json([
            'product_id' => $productId,
            'addons' => $pick($addons),
            'similar' => $pick($similar),
            'locale' => $ctx->locale,
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
