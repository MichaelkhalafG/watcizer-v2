<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CompatListing;
use App\Compat\CompatRelated;
use App\Compat\CompatServices;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The legacy read endpoints the Watchizer storefront calls today (CLEAN_CORE_STUDY §3.3 "move"
 * rows), answered from the clean tables with the legacy JSON, byte for byte.
 *
 * Appended translated attributes: `all_product` is pinned to `compat.pinned_locale` (EN) — the
 * legacy host caches its `->toArray()` and is locale-blind (D-13); `catalog/meta` and
 * `show_shipping_city` follow the negotiated request locale — the legacy host caches Eloquent
 * models there and serialises them per request (flag F-18). Verified live 2026-09-08.
 */
class CatalogCompatController extends Controller
{
    public function __construct(private readonly CompatServices $compat) {}

    public function meta(): JsonResponse
    {
        return response()->json($this->compat->meta->build(app()->getLocale()));
    }

    public function allProduct(): JsonResponse
    {
        return response()->json($this->compat->catalog->allProduct($this->locale()));
    }

    /** The header menu's catalogue facts (C-1 stage 2) — storefront-only, no legacy counterpart. */
    public function nav(): JsonResponse
    {
        return response()->json($this->compat->catalog->nav(), 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * The listing: one page of raw product rows plus every facet count (C-1 stage 3) — what the
     * storefront computed over the whole catalogue in the browser. Storefront-only, no legacy
     * counterpart. Filters are id lists ("brands=3,7"), genders English names, as the storefront's
     * own filter state holds them.
     */
    public function listing(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', CompatListing::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:96'],
            // Accepted and ignored: search reads both languages since 2026-09-27; the stage-3 storefront still sends it.
            'lang' => ['nullable', 'string', 'in:en,ar'],
            'minPrice' => ['nullable', 'numeric', 'min:0'],
            'maxPrice' => ['nullable', 'numeric', 'min:0'],
            'genders' => ['nullable', 'string', 'max:200'],
            'offers' => ['nullable', 'string', 'in:0,1,true,false'],
        ]);
        $ids = function (string $key) use ($request): array {
            $raw = $request->query($key);
            $out = [];
            foreach (explode(',', is_string($raw) ? $raw : '') as $part) {
                if (preg_match('/^\d{1,9}$/', trim($part)) === 1) {
                    $out[] = (int) trim($part);
                }
            }

            return array_values(array_unique($out));
        };
        $genders = array_values(array_filter(array_map('trim', explode(',', $request->string('genders')->toString())), fn (string $g): bool => $g !== ''));
        $filters = [
            'brands' => $ids('brands'), 'categories' => $ids('categories'), 'subTypes' => $ids('subTypes'),
            'genders' => $genders, 'offers' => in_array($request->query('offers'), ['1', 'true'], true),
            'price' => [$request->float('minPrice', 0), $request->float('maxPrice', CompatListing::PRICE_MAX)],
            'dialColors' => $ids('dialColors'), 'bandColors' => $ids('bandColors'), 'materials' => $ids('materials'),
            'movements' => $ids('movements'), 'shapes' => $ids('shapes'), 'displayTypes' => $ids('displayTypes'),
            'grades' => $ids('grades'),
        ];

        $listing = $this->compat->listing;
        $payload = $listing->query(
            $filters,
            $request->string('q')->toString(),
            $request->string('sort', 'default')->toString(),
            $request->integer('page', 1),
            $request->integer('per_page', 24),
        );
        $t = $listing->timing;

        // Where the server's time went, readable from the storefront's browser (Timing-Allow-Origin:
        // timings only, no data), so a slow tap can be taken apart instead of guessed at.
        return response()->json($payload, 200, [
            'Server-Timing' => sprintf('index;dur=%.1f;desc="%s", filter;dur=%.1f, cards;dur=%.1f', $t['index'], $t['cold'] ? 'built' : 'cached', $t['filter'], $t['cards']),
            'Timing-Allow-Origin' => '*',
        ], JSON_UNESCAPED_UNICODE);
    }

    /** Raw product rows (with ratings and gallery images) for up to 100 ids — the cart drawer's lines (C-1 stage 3). */
    public function cards(Request $request): JsonResponse
    {
        $request->validate(['ids' => ['required', 'string', 'max:1000']]);
        $ids = [];
        foreach (explode(',', $request->string('ids')->toString()) as $part) {
            if (preg_match('/^\d{1,9}$/', trim($part)) === 1) {
                $ids[] = (int) trim($part);
            }
        }

        return response()->json($this->compat->listing->cards(array_slice(array_values(array_unique($ids)), 0, 100)), 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET catalog/product?slug= — ONE product for the product page, by its URL slug or id, as cards in
     * the `catalog/cards` shape (C-1 stage 4). The page used to load the whole catalogue to find it.
     * 404 when no visible product has that slug.
     */
    public function product(Request $request): JsonResponse
    {
        $request->validate(['slug' => ['required', 'string', 'max:300']]);
        $id = $this->compat->listing->idForParam(rawurldecode($request->string('slug')->toString()));
        if ($id === null) {
            return response()->json(['products' => [], 'ratings' => [], 'images' => []], 404);
        }

        return response()->json($this->compat->listing->cards([$id]), 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET catalog/home — the home page's rails, in the dashboard's order, each with its product ids,
     * plus every card they need in the `catalog/cards` shape (C-1 stage 4, slice C, `CompatHome`).
     * The home page used to load the whole catalogue to build these in the browser.
     */
    public function home(): JsonResponse
    {
        return response()->json($this->compat->home->build(), 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET catalog/related — the suggestion rails, as product cards in the `catalog/cards` shape, best
     * first (C-1 stage 4, `CompatRelated`, the developer's rules of 2026-09-29):
     *   ?cart=ID,ID,…                          the cart's add-ons ("Complete the look")
     *   ?product=ID&kind=addons&exclude=…      the product page's add-ons ("Pairs well with")
     *   ?product=ID&kind=similar&exclude=…     the product page's alternatives ("Similar styles")
     * `exclude` is what is already in the cart — never suggested.
     */
    public function related(Request $request): JsonResponse
    {
        $request->validate([
            'product' => ['nullable', 'integer', 'min:1', 'required_without:cart'],
            'cart' => ['nullable', 'string', 'max:1000', 'required_without:product'],
            'kind' => ['nullable', 'string', 'in:addons,similar'],
            'exclude' => ['nullable', 'string', 'max:1000'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);
        $ids = function (string $key) use ($request): array {
            $out = [];
            foreach (explode(',', $request->string($key)->toString()) as $part) {
                if (preg_match('/^\d{1,9}$/', trim($part)) === 1) {
                    $out[] = (int) trim($part);
                }
            }

            return array_slice(array_values(array_unique($out)), 0, 50);
        };
        $exclude = $ids('exclude');
        if ($request->filled('cart')) {
            $cart = $ids('cart');
            $result = $this->compat->related->addOns($cart, $cart, $request->integer('limit', CompatRelated::LIMIT));
        } elseif ($request->string('kind')->toString() === 'addons') {
            $result = $this->compat->related->addOns([$request->integer('product')], $exclude, $request->integer('limit', CompatRelated::ADDON_LIMIT));
        } else {
            $result = $this->compat->related->similar($request->integer('product'), $exclude, $request->integer('limit', CompatRelated::LIMIT));
        }

        return response()->json($this->compat->listing->cards($result), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function allProductImage(): JsonResponse
    {
        return response()->json($this->compat->catalog->allProductImage());
    }

    public function allProductRating(): JsonResponse
    {
        return response()->json($this->compat->catalog->allProductRating());
    }

    public function shippingCities(): JsonResponse
    {
        return response()->json($this->compat->catalog->shippingCities(app()->getLocale()));
    }

    public function show(string $id): JsonResponse
    {
        $productId = self::legacyId($id);
        $payload = $productId === null ? null : $this->compat->detail->byId($productId);
        if ($payload === null) {
            // Generic body on purpose (review 🟡-11): no model class names, no ids echoed back.
            throw new NotFoundHttpException('Not Found');
        }

        return response()->json($payload);
    }

    public function showByName(string $name): JsonResponse
    {
        $payload = $this->compat->detail->byName($name);
        if ($payload === null) {
            throw new NotFoundHttpException('Not Found');
        }

        return response()->json($payload);
    }

    /**
     * The legacy `findOrFail($id)` compares the raw path segment with MySQL's loose string→number
     * coercion: `4.0`, `04`, ` 4` and `4abc` all match product 4, `4.5` and `abc` match nothing
     * (review 🟠-4). Reproduced with PHP's numeric-prefix cast; only positive integers resolve.
     */
    public static function legacyId(string $raw): ?int
    {
        $n = (float) $raw;
        if ($n <= 0 || $n !== floor($n) || $n > PHP_INT_MAX) {
            return null;
        }

        return (int) $n;
    }

    private function locale(): string
    {
        return config()->string('compat.pinned_locale');
    }
}
