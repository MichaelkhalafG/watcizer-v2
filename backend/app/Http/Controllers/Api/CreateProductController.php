<?php

namespace App\Http\Controllers\Api;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Grade;
use App\Models\Shape;
use App\Models\Gender;
use App\Models\Feature;
use App\Models\SubType;
use App\Models\Material;
use App\Models\SizeType;
use App\Models\ClosureType;
use App\Models\DisplayType;
use App\Models\CategoryType;
use App\Models\MovementType;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CreateProductController extends Controller
{
    public function AllBrand()
    {
        try {
            $cacheKey = 'AllBrand';

            $brand = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Brand::all();
            });

            return response()->json($brand);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllGrade()
    {
        try {
            $cacheKey = 'AllGrade';

            $grade = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Grade::all();
            });

            return response()->json($grade);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllSubType()
    {
        try {
            $cacheKey = 'AllSubType';

            $sub_type = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return SubType::all();
            });

            return response()->json($sub_type);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllCategoryType()
    {
        try {
            $cacheKey = 'AllCategoryType';

            $category_type = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return CategoryType::all();
            });

            return response()->json($category_type);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllColor()
    {
        try {
            $cacheKey = 'AllColor';

            $color = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Color::all();
            });

            return response()->json($color);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllClosureType()
    {
        try {
            $cacheKey = 'AllClosureType';

            $closure_type = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return ClosureType::all();
            });

            return response()->json($closure_type);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllDisplayType()
    {
        try {
            $cacheKey = 'AllDisplayType';

            $display_type = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return DisplayType::all();
            });

            return response()->json($display_type);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllSizeType()
    {
        try {
            $cacheKey = 'AllSizeType';

            $size_type = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return SizeType::all();
            });

            return response()->json($size_type);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllShape()
    {
        try {
            $cacheKey = 'AllShape';

            $shape = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Shape::all();
            });

            return response()->json($shape);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllMaterial()
    {
        try {
            $cacheKey = 'AllMaterial';

            $material = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Material::all();
            });

            return response()->json($material);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllFeature()
    {
        try {
            $cacheKey = 'AllFeature';

            $feature = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Feature::all();
            });

            return response()->json($feature);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllMovementType()
    {
        try {
            $cacheKey = 'AllMovementType';

            $movement_type = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return MovementType::all();
            });

            return response()->json($movement_type);

        } catch (\Exception $e) {
            Log::error($e);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred',
                'ref'     => \Illuminate\Support\Str::uuid()
            ], 500);
        }
    }

    public function AllGender()
    {
        try {
            $cacheKey = 'AllGender';

            $gender = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Gender::all();
            });

            return response()->json($gender);

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
