<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\CategoryTree;
use App\Storefront\StorefrontContext;
use App\Support\Val;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CategoryController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function tree(CategoryTree $tree, StorefrontContext $ctx): JsonResponse
    {
        return response()->json(['tree' => $tree->nested(), 'locale' => $ctx->locale], 200, [], self::JSON);
    }

    /** A node by slug path (each segment the child of the previous) + breadcrumb + visible children. */
    public function show(string $path, CategoryTree $tree, StorefrontContext $ctx): JsonResponse
    {
        $node = $tree->byPath($path);
        if ($node === null || $node['path'] !== trim($path, '/')) {
            throw new NotFoundHttpException('Category not found');
        }
        $id = Val::int($node, 'id');
        $ctx->tags->category($ctx->id(), $id);

        return response()->json([
            'category' => CategoryTree::item($node),
            'breadcrumb' => $tree->breadcrumb($id),
            'children' => $tree->children($id),
            'locale' => $ctx->locale,
        ], 200, [], self::JSON);
    }
}
