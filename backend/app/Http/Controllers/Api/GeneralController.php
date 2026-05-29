<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\Category;
use App\Models\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Blog;
use Illuminate\Support\Facades\Cache;

class GeneralController extends Controller
{
    public function AllCategory()
    {
        try {
            $cacheKey = 'AllCategory';

            $categories = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Category::all();
            });

            return response()->json($categories);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching categories',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AllBlog()
    {
        try {
            $cacheKey = 'AllBlog';

            $blogs = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Blog::with('images')->get();
            });

            return response()->json($blogs);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching blogs',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}