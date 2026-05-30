<?php

namespace App\Http\Controllers\Api;

use App\Models\Category;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CategoryController extends Controller
{

    public function mainCategories()
    {
        try {

            $data = Cache::remember('main_categories', now()->addMinutes(30), function () {

                return Category::active()->main()->orderBy('sort_order')->get()
                    ->map(fn($c) => [
                        'id'      => $c->id,
                        'slug'    => $c->slug,
                        'name'    => $c->name,
                        'name_ar' => $c->translate('ar')?->name,
                        'name_en' => $c->translate('en')?->name,
                    ]);

            });

            return response()->json($data);

        } catch (\Exception $e) {

            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);

        }
    }


    public function children(int $parentId)
    {
        try {

            $data = Cache::remember("category_children_{$parentId}", now()->addMinutes(30), function () use ($parentId) {

                return Category::active()->childrenOf($parentId)->orderBy('sort_order')->get()
                    ->map(fn($c) => [
                        'id'      => $c->id,
                        'slug'    => $c->slug,
                        'level'   => $c->level,
                        'name'    => $c->name,
                        'name_ar' => $c->translate('ar')?->name,
                        'name_en' => $c->translate('en')?->name,
                    ]);

            });

            return response()->json($data);

        } catch (\Exception $e) {

            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);

        }
    }


    public function tree()
    {

        $categories = Category::with([
                'translations',
                'children.translations',
                'children.children.translations'
            ])
            ->whereNull('parent_id')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($cat) {

                return $this->formatCategory($cat);

            });

        return response()->json($categories);

    }


    private function formatCategory($cat)
    {

        return [

            'id'       => $cat->id,
            'slug'     => $cat->slug,
            'level'    => $cat->level,

            'name_en'  => $cat->translations->where('locale', 'en')->first()?->name ?? $cat->slug,
            'name_ar'  => $cat->translations->where('locale', 'ar')->first()?->name ?? $cat->slug,

            'children' => $cat->children
                ->map(fn($child) => $this->formatCategory($child))
                ->values()

        ];

    }

}
