<?php

namespace App\Http\Controllers\Api;

use App\Models\BannerHome;
use App\Models\BannerSide;
use App\Models\BannerBottom;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BannerController extends Controller
{
    public function AllBannerHome()
    {
        try {
            $cacheKey = 'AllBannerHome';

            $all_banner_home = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return BannerHome::all();
            });

            return response()->json($all_banner_home);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllBannerSide()
    {
        try {
            $cacheKey = 'AllBannerSide';

            $all_banner_side = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return BannerSide::all();
            });

            return response()->json($all_banner_side);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllBannerBottom()
    {
        try {
            $cacheKey = 'AllBannerBottom';

            $all_banner_bottom = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return BannerBottom::all();
            });

            return response()->json($all_banner_bottom);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }
}
