<?php

namespace App\Http\Controllers\Api;

use App\Models\Offer;
use App\Models\Product;
use App\Models\Wishlist;
use App\Models\OfferRating;
use App\Models\ProductImage;
use App\Models\WishlistItem;
use Illuminate\Http\Request;
use App\Models\ProductRating;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class DetailsProductController extends Controller
{
    public function AllProduct()
    {
        try {
            $cacheKey = 'AllProduct';

            $product = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Product::with(
                    'feature',
                    'gender',
                    'dialColor',
                    'bandColor',
                    'translations'
                )->get();
            });

            return response()->json($product);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching products',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AllProductImage()
    {
        try {
            $cacheKey = 'AllProductImage';

            $product_image = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return ProductImage::all();
            });

            return response()->json($product_image);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching product images',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AllProductRating()
    {
        try {
            $cacheKey = 'AllProductRating';

            $product_rating = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return ProductRating::all();
            });

            return response()->json($product_rating);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching product ratings',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AddProductRating(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|integer|exists:products,id',
                'rating'     => 'required|numeric|min:1|max:5',
                'comment'    => 'nullable|string',
            ]);

            ProductRating::create([
                'product_id' => $request->product_id,
                'rating'     => $request->rating,
                'comment'    => $request->comment,
                'user_id'    => $request->user_id,
                'date'       => date('Y-m-d H:i'),
            ]);

            $product = Product::with('product_rating')->where('id', $request->product_id)->first();

            $averageRating         = $product->product_rating()->avg('rating');
            $product->average_rate = $averageRating;
            $product->save();

            Cache::forget('AllProductRating');

            return response()->json(['success' => true, 'message' => 'Rating added successfully'], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while adding rating',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AllOffer()
    {
        try {
            $cacheKey = 'AllOffer';

            $all_offer = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Offer::with('offer_rating')->get();
            });

            return response()->json($all_offer);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching offers',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AllOfferRating()
    {
        try {
            $cacheKey = 'AllOfferRating';

            $offer_rating = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return OfferRating::all();
            });

            return response()->json($offer_rating);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching offer ratings',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AddOfferRating(Request $request)
    {
        try {
            $request->validate([
                'offer_id' => 'required|integer|exists:offers,id',
                'rating'   => 'required|numeric|min:1|max:5',
                'comment'  => 'nullable|string',
            ]);

            OfferRating::create([
                'offer_id' => $request->offer_id,
                'rating'   => $request->rating,
                'comment'  => $request->comment,
                'user_id'  => $request->user_id,
                'date'     => date('Y-m-d H:i'),
            ]);

            $offer = Offer::with('offer_rating')->where('id', $request->offer_id)->first();

            $averageRating       = $offer->offer_rating()->avg('rating');
            $offer->average_rate = $averageRating;
            $offer->save();

            Cache::forget('AllOfferRating');

            return response()->json(['success' => true, 'message' => 'Rating added successfully'], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while adding offer rating',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AddWishlist(Request $request)
    {
        try {
            $request->validate([
                'user_id'    => 'required|integer|exists:users,id',
                'product_id' => 'nullable|integer|exists:products,id',
                'offer_id'   => 'nullable|integer|exists:offers,id',
            ]);

            $wishlist     = Wishlist::firstOrCreate(['user_id' => $request->user_id]);
            $wishlistItem = $wishlist->wishlist_item()->updateOrCreate([
                'product_id' => $request->product_id,
                'offer_id'   => $request->offer_id,
            ]);

            Cache::forget('AllWishlist');

            return response()->json(['success' => true, 'message' => 'Wishlist added successfully'], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while adding wishlist',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function AllWishlist()
    {
        try {
            $cacheKey = 'AllWishlist';

            $wishlist = Cache::remember($cacheKey, now()->addMinutes(10), function () {
                return Wishlist::with('wishlist_item')->get();
            });

            return response()->json($wishlist);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching wishlists',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function DeleteWishlist($id)
    {
        try {
            $wishlist_item = WishlistItem::find($id);

            if (!$wishlist_item) {
                return response()->json(['message' => 'Wishlist not found'], 404);
            }

            $wishlist_item->delete();

            Cache::forget('AllWishlist');

            return response()->json(['success' => true, 'message' => 'Wishlist deleted successfully'], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting Wishlist',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}