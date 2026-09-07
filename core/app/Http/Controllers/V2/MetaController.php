<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\Meta;
use Illuminate\Http\JsonResponse;

class MetaController extends Controller
{
    public function show(Meta $meta): JsonResponse
    {
        return response()->json($meta->build(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
