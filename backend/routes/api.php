<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\GeneralController;
use App\Http\Controllers\Api\CreateProductController;
use App\Http\Controllers\Api\DetailsProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductVariantApiController;

Route::middleware(['api', 'CheckApi'])->group(function () {

    // General //
    Route::get('all_category', [GeneralController::class, 'AllCategory']);
    Route::get('all_blog',     [GeneralController::class, 'AllBlog']);

    // Banners //
    Route::get('all_banner_home',   [BannerController::class, 'AllBannerHome']);
    Route::get('all_banner_side',   [BannerController::class, 'AllBannerSide']);
    Route::get('all_banner_bottom', [BannerController::class, 'AllBannerBottom']);

    // Create a product //
    Route::get('all_brand',         [CreateProductController::class, 'AllBrand']);
    Route::get('all_grade',         [CreateProductController::class, 'AllGrade']);
    Route::get('all_sub_type',      [CreateProductController::class, 'AllSubType']);
    Route::get('all_category_type', [CreateProductController::class, 'AllCategoryType']);
    Route::get('all_color',         [CreateProductController::class, 'AllColor']);
    Route::get('all_closure_type',  [CreateProductController::class, 'AllClosureType']);
    Route::get('all_display_type',  [CreateProductController::class, 'AllDisplayType']);
    Route::get('all_size_type',     [CreateProductController::class, 'AllSizeType']);
    Route::get('all_shape',         [CreateProductController::class, 'AllShape']);
    Route::get('all_material',      [CreateProductController::class, 'AllMaterial']);
    Route::get('all_feature',       [CreateProductController::class, 'AllFeature']);
    Route::get('all_movement_type', [CreateProductController::class, 'AllMovementType']);
    Route::get('all_gender',        [CreateProductController::class, 'AllGender']);

    // Details Product //
    Route::get('all_product',             [DetailsProductController::class, 'AllProduct']);
    Route::get('all_product_image',       [DetailsProductController::class, 'AllProductImage']);
    Route::get('all_product_rating',      [DetailsProductController::class, 'AllProductRating']);
    Route::post('add_product_rating',     [DetailsProductController::class, 'AddProductRating']);
    Route::get('all_offer',               [DetailsProductController::class, 'AllOffer']);
    Route::get('all_offer_rating',        [DetailsProductController::class, 'AllOfferRating']);
    Route::post('add_offer_rating',       [DetailsProductController::class, 'AddOfferRating']);
    Route::post('add_wishlist',           [DetailsProductController::class, 'AddWishlist']);
    Route::get('all_wishlist',            [DetailsProductController::class, 'AllWishlist']);
    Route::get('all_wishlist/{user_id}',  [DetailsProductController::class, 'AllWishlist']);
    Route::delete('delete_wishlist/{id}', [DetailsProductController::class, 'DeleteWishlist']);

    // Orders //
    Route::get('show_shipping_city',   [OrderController::class, 'ShowShippingCity']);
    Route::post('add_address',         [OrderController::class, 'AddAddress']);
    Route::get('show_address',         [OrderController::class, 'ShowAddress']);
    Route::post('add_to_cart',         [OrderController::class, 'AddToCart']);
    Route::get('show_cart',            [OrderController::class, 'ShowCart']);
    Route::get('show_cart/{user_id}',  [OrderController::class, 'ShowCart']);
    Route::delete('delete_cart/{id}',  [OrderController::class, 'DeleteCart']);
    Route::post('add_order',           [OrderController::class, 'AddOrder']);
    Route::get('show_order',           [OrderController::class, 'ShowOrder']);

    // Auth //
    Route::get('all_user',         [AuthController::class, 'AllUser']);
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('logout',          [AuthController::class, 'logout']);
    Route::post('updatePassword',  [AuthController::class, 'updatePassword']);
    Route::post('updateProfile',   [AuthController::class, 'updateProfile']);

    // ── NEW: Variants & Colors & Sizes ────────────────────────
    Route::get('products/{product}/variants',         [ProductVariantApiController::class, 'index']);
    Route::get('products/{product}/variants/summary', [ProductVariantApiController::class, 'summary']);
    Route::get('new_colors',                          [ProductVariantApiController::class, 'colors']);
    Route::get('new_sizes',                           [ProductVariantApiController::class, 'sizes']);

});

// Category Hierarchy API (no auth - used by admin dashboard)
Route::prefix('categories')->group(function () {
    Route::get('/main',                [CategoryController::class, 'mainCategories']);
    Route::get('/{parentId}/children', [CategoryController::class, 'children']);
    Route::get('/tree',                [CategoryController::class, 'tree']);
});

Route::get('callback_payment', [OrderController::class, 'CallbackPayment']);