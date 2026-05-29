<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\ColorController;
use App\Http\Controllers\Admin\GradeController;
use App\Http\Controllers\Admin\OfferController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ShapeController;
use App\Http\Controllers\Admin\GenderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\FeatureController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SubTypeController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\MaterialController;
use App\Http\Controllers\Admin\SizeTypeController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\BannerHomeController;
use App\Http\Controllers\Admin\BannerSideController;
use App\Http\Controllers\Admin\ClosureTypeController;
use App\Http\Controllers\Admin\DisplayTypeController;
use App\Http\Controllers\Admin\OfferRatingController;
use App\Http\Controllers\Admin\BannerBottomController;
use App\Http\Controllers\Admin\CategoryTypeController;
use App\Http\Controllers\Admin\MovementTypeController;
use App\Http\Controllers\Admin\ProductImageController;
use App\Http\Controllers\Admin\ShippingCityController;
use App\Http\Controllers\Admin\ProductRatingController;
use App\Http\Controllers\Admin\NewColorController;
use App\Http\Controllers\Admin\NewSizeController;
use App\Http\Controllers\Admin\ProductVariantController;
use App\Http\Controllers\SitemapController;

Route::get('/sitemap.xml', [SitemapController::class, 'index']);
Route::get('/robots.txt', function () {
    $content = file_get_contents(public_path('robots.txt'));
    return response($content, 200)->header('Content-Type', 'text/plain');
});

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->middleware(['auth', 'IsAdminOrSuperAdmin'])->group(function () {

    //// Dashboard Route ////
    Route::controller(DashboardController::class)->group(function () {
        Route::get('/dashboard',                                'index')->name('dashboard');
        Route::get('/dashboard/get_sales',                      'get_sales')->name('dashboard.get_sales');
        Route::get('/dashboard/get_profit',                     'get_profit')->name('dashboard.get_profit');
        Route::get('/dashboard/get_order_total_price',          'get_order_total_price')->name('dashboard.get_order_total_price');
        Route::get('/dashboard/get_order_total_price_shipping', 'get_order_total_price_shipping')->name('dashboard.get_order_total_price_shipping');
        Route::get('/dashboard/get_customer',                   'get_customer')->name('dashboard.get_customer');
        Route::get('/dashboard/get_top_selling',                'get_top_selling')->name('dashboard.get_top_selling');
    });

    //// Brand Route ////
    Route::controller(BrandController::class)->group(function () {
        Route::get('/brand',              'index')->name('brand.index');
        Route::get('/brand/create',       'create')->name('brand.create');
        Route::post('/brand',             'store')->name('brand.store');
        Route::get('/brand/{brand}/edit', 'edit')->name('brand.edit')->middleware('can:AnyAction');
        Route::put('/brand/{brand}',      'update')->name('brand.update')->middleware('can:AnyAction');
        Route::delete('/brand/{brand}',   'destroy')->name('brand.destroy')->middleware('can:AnyAction');
        Route::get('/brand/export',       'export')->name('brand.export');
        Route::post('/brand/import',      'import')->name('brand.import');
    });

    //// Grade Route ////
    Route::controller(GradeController::class)->group(function () {
        Route::get('/grade',              'index')->name('grade.index');
        Route::get('/grade/create',       'create')->name('grade.create');
        Route::post('/grade',             'store')->name('grade.store');
        Route::get('/grade/{grade}/edit', 'edit')->name('grade.edit')->middleware('can:AnyAction');
        Route::put('/grade/{grade}',      'update')->name('grade.update')->middleware('can:AnyAction');
        Route::delete('/grade/{grade}',   'destroy')->name('grade.destroy')->middleware('can:AnyAction');
        Route::get('/grade/export',       'export')->name('grade.export');
        Route::post('/grade/import',      'import')->name('grade.import');
    });

    //// SubType Route ////
    Route::controller(SubTypeController::class)->group(function () {
        Route::get('/sub_type',                 'index')->name('sub_type.index');
        Route::get('/sub_type/create',          'create')->name('sub_type.create');
        Route::post('/sub_type',                'store')->name('sub_type.store');
        Route::get('/sub_type/{sub_type}/edit', 'edit')->name('sub_type.edit')->middleware('can:AnyAction');
        Route::put('/sub_type/{sub_type}',      'update')->name('sub_type.update')->middleware('can:AnyAction');
        Route::delete('/sub_type/{sub_type}',   'destroy')->name('sub_type.destroy')->middleware('can:AnyAction');
        Route::get('/sub_type/export',          'export')->name('sub_type.export');
        Route::post('/sub_type/import',         'import')->name('sub_type.import');
    });

    //// CategoryType Route ////
    Route::controller(CategoryTypeController::class)->group(function () {
        Route::get('/category_type',                      'index')->name('category_type.index');
        Route::get('/category_type/create',               'create')->name('category_type.create');
        Route::post('/category_type',                     'store')->name('category_type.store');
        Route::get('/category_type/{category_type}/edit', 'edit')->name('category_type.edit')->middleware('can:AnyAction');
        Route::put('/category_type/{category_type}',      'update')->name('category_type.update')->middleware('can:AnyAction');
        Route::delete('/category_type/{category_type}',   'destroy')->name('category_type.destroy')->middleware('can:AnyAction');
        Route::get('/category_type/export',               'export')->name('category_type.export');
        Route::post('/category_type/import',              'import')->name('category_type.import');
    });

    //// Category Route ////
    Route::controller(CategoryController::class)->group(function () {
        Route::get('/category',                 'index')->name('category.index');
        Route::get('/category/create',          'create')->name('category.create');
        Route::post('/category',                'store')->name('category.store');
        Route::get('/category/{category}/edit', 'edit')->name('category.edit')->middleware('can:AnyAction');
        Route::put('/category/{category}',      'update')->name('category.update')->middleware('can:AnyAction');
        Route::delete('/category/{category}',   'destroy')->name('category.destroy')->middleware('can:AnyAction');
        Route::get('/category/export',          'export')->name('category.export');
        Route::post('/category/import',         'import')->name('category.import');
    });

    //// Color Route (OLD) ////
    Route::controller(ColorController::class)->group(function () {
        Route::get('/color',              'index')->name('color.index');
        Route::get('/color/create',       'create')->name('color.create');
        Route::post('/color',             'store')->name('color.store');
        Route::get('/color/{color}/edit', 'edit')->name('color.edit')->middleware('can:AnyAction');
        Route::put('/color/{color}',      'update')->name('color.update')->middleware('can:AnyAction');
        Route::delete('/color/{color}',   'destroy')->name('color.destroy')->middleware('can:AnyAction');
        Route::get('/color/export',       'export')->name('color.export');
        Route::post('/color/import',      'import')->name('color.import');
    });

    //// New Colors Route (Variants System) ////
    Route::controller(NewColorController::class)->group(function () {
        Route::get('/new-colors',                   'index')->name('new-colors.index');
        Route::get('/new-colors/create',            'create')->name('new-colors.create');
        Route::post('/new-colors',                  'store')->name('new-colors.store');
        Route::get('/new-colors/{color}/edit',      'edit')->name('new-colors.edit');
        Route::put('/new-colors/{color}',           'update')->name('new-colors.update');
        Route::delete('/new-colors/{color}',        'destroy')->name('new-colors.destroy');
    });

    //// New Sizes Route (Variants System) ////
    Route::controller(NewSizeController::class)->group(function () {
        Route::get('/new-sizes',                'index')->name('new-sizes.index');
        Route::get('/new-sizes/create',         'create')->name('new-sizes.create');
        Route::post('/new-sizes',               'store')->name('new-sizes.store');
        Route::get('/new-sizes/{size}/edit',    'edit')->name('new-sizes.edit');
        Route::put('/new-sizes/{size}',         'update')->name('new-sizes.update');
        Route::delete('/new-sizes/{size}',      'destroy')->name('new-sizes.destroy');
    });

    //// Product Variants Route ////
    Route::prefix('products/{product}/variants')->name('products.variants.')->group(function () {
        Route::get('/',             [ProductVariantController::class, 'index'])->name('index');
        Route::get('/create',       [ProductVariantController::class, 'create'])->name('create');
        Route::post('/',            [ProductVariantController::class, 'store'])->name('store');
        Route::put('/{variant}',    [ProductVariantController::class, 'update'])->name('update');
        Route::delete('/{variant}', [ProductVariantController::class, 'destroy'])->name('destroy');
        Route::post('/generate',    [ProductVariantController::class, 'generate'])->name('generate');
    });

    //// Closure Type Route ////
    Route::controller(ClosureTypeController::class)->group(function () {
        Route::get('/closure_type',                     'index')->name('closure_type.index');
        Route::get('/closure_type/create',              'create')->name('closure_type.create');
        Route::post('/closure_type',                    'store')->name('closure_type.store');
        Route::get('/closure_type/{closure_type}/edit', 'edit')->name('closure_type.edit')->middleware('can:AnyAction');
        Route::put('/closure_type/{closure_type}',      'update')->name('closure_type.update')->middleware('can:AnyAction');
        Route::delete('/closure_type/{closure_type}',   'destroy')->name('closure_type.destroy')->middleware('can:AnyAction');
        Route::get('/closure_type/export',              'export')->name('closure_type.export');
        Route::post('/closure_type/import',             'import')->name('closure_type.import');
    });

    //// Display Type Route ////
    Route::controller(DisplayTypeController::class)->group(function () {
        Route::get('/display_type',                     'index')->name('display_type.index');
        Route::get('/display_type/create',              'create')->name('display_type.create');
        Route::post('/display_type',                    'store')->name('display_type.store');
        Route::get('/display_type/{display_type}/edit', 'edit')->name('display_type.edit')->middleware('can:AnyAction');
        Route::put('/display_type/{display_type}',      'update')->name('display_type.update')->middleware('can:AnyAction');
        Route::delete('/display_type/{display_type}',   'destroy')->name('display_type.destroy')->middleware('can:AnyAction');
        Route::get('/display_type/export',              'export')->name('display_type.export');
        Route::post('/display_type/import',             'import')->name('display_type.import');
    });

    //// Size Type Route ////
    Route::controller(SizeTypeController::class)->group(function () {
        Route::get('/size_type',                  'index')->name('size_type.index');
        Route::get('/size_type/create',           'create')->name('size_type.create');
        Route::post('/size_type',                 'store')->name('size_type.store');
        Route::get('/size_type/{size_type}/edit', 'edit')->name('size_type.edit')->middleware('can:AnyAction');
        Route::put('/size_type/{size_type}',      'update')->name('size_type.update')->middleware('can:AnyAction');
        Route::delete('/size_type/{size_type}',   'destroy')->name('size_type.destroy')->middleware('can:AnyAction');
        Route::get('/size_type/export',           'export')->name('size_type.export');
        Route::post('/size_type/import',          'import')->name('size_type.import');
    });

    //// Shape Route ////
    Route::controller(ShapeController::class)->group(function () {
        Route::get('/shape',              'index')->name('shape.index');
        Route::get('/shape/create',       'create')->name('shape.create');
        Route::post('/shape',             'store')->name('shape.store');
        Route::get('/shape/{shape}/edit', 'edit')->name('shape.edit')->middleware('can:AnyAction');
        Route::put('/shape/{shape}',      'update')->name('shape.update')->middleware('can:AnyAction');
        Route::delete('/shape/{shape}',   'destroy')->name('shape.destroy')->middleware('can:AnyAction');
        Route::get('/shape/export',       'export')->name('shape.export');
        Route::post('/shape/import',      'import')->name('shape.import');
    });

    //// Material Route ////
    Route::controller(MaterialController::class)->group(function () {
        Route::get('/material',                 'index')->name('material.index');
        Route::get('/material/create',          'create')->name('material.create');
        Route::post('/material',                'store')->name('material.store');
        Route::get('/material/{material}/edit', 'edit')->name('material.edit')->middleware('can:AnyAction');
        Route::put('/material/{material}',      'update')->name('material.update')->middleware('can:AnyAction');
        Route::delete('/material/{material}',   'destroy')->name('material.destroy')->middleware('can:AnyAction');
        Route::get('/material/export',          'export')->name('material.export');
        Route::post('/material/import',         'import')->name('material.import');
    });

    //// Feature Route ////
    Route::controller(FeatureController::class)->group(function () {
        Route::get('/feature',                'index')->name('feature.index');
        Route::get('/feature/create',         'create')->name('feature.create');
        Route::post('/feature',               'store')->name('feature.store');
        Route::get('/feature/{feature}/edit', 'edit')->name('feature.edit')->middleware('can:AnyAction');
        Route::put('/feature/{feature}',      'update')->name('feature.update')->middleware('can:AnyAction');
        Route::delete('/feature/{feature}',   'destroy')->name('feature.destroy')->middleware('can:AnyAction');
        Route::get('/feature/export',         'export')->name('feature.export');
        Route::post('/feature/import',        'import')->name('feature.import');
    });

    //// Movement Type Route ////
    Route::controller(MovementTypeController::class)->group(function () {
        Route::get('/movement_type',                      'index')->name('movement_type.index');
        Route::get('/movement_type/create',               'create')->name('movement_type.create');
        Route::post('/movement_type',                     'store')->name('movement_type.store');
        Route::get('/movement_type/{movement_type}/edit', 'edit')->name('movement_type.edit')->middleware('can:AnyAction');
        Route::put('/movement_type/{movement_type}',      'update')->name('movement_type.update')->middleware('can:AnyAction');
        Route::delete('/movement_type/{movement_type}',   'destroy')->name('movement_type.destroy')->middleware('can:AnyAction');
        Route::get('/movement_type/export',               'export')->name('movement_type.export');
        Route::post('/movement_type/import',              'import')->name('movement_type.import');
    });

    //// Gender Route ////
    Route::controller(GenderController::class)->group(function () {
        Route::get('/gender',               'index')->name('gender.index');
        Route::get('/gender/create',        'create')->name('gender.create');
        Route::post('/gender',              'store')->name('gender.store');
        Route::get('/gender/{gender}/edit', 'edit')->name('gender.edit')->middleware('can:AnyAction');
        Route::put('/gender/{gender}',      'update')->name('gender.update')->middleware('can:AnyAction');
        Route::delete('/gender/{gender}',   'destroy')->name('gender.destroy')->middleware('can:AnyAction');
        Route::get('/gender/export',        'export')->name('gender.export');
        Route::post('/gender/import',       'import')->name('gender.import');
    });

    //// Product Route ////
    Route::controller(ProductController::class)->group(function () {
        Route::get('/product',                'index')->name('product.index');
        Route::get('/product/create',         'create')->name('product.create');
        Route::post('/product',               'store')->name('product.store');
        Route::get('/product/{product}',      'show')->name('product.show');
        Route::get('/product/{product}/edit', 'edit')->name('product.edit');
        Route::put('/product/{product}',      'update')->name('product.update');
        Route::delete('/product/{product}',   'destroy')->name('product.destroy')->middleware('can:AnyAction');
        Route::get('/products/export',        'export')->name('product.export');
        Route::post('/product/import',        'import')->name('product.import');
    });

    //// Product Image Route (old) ////
    Route::controller(ProductImageController::class)->group(function () {
        Route::get('/product_image',                      'index')->name('product_image.index');
        Route::get('/product_image/create',               'create')->name('product_image.create');
        Route::post('/product_image',                     'store')->name('product_image.store');
        Route::get('/product_image/{product_image}/edit', 'edit')->name('product_image.edit')->middleware('can:AnyAction');
        Route::put('/product_image/{product_image}',      'update')->name('product_image.update')->middleware('can:AnyAction');
        Route::delete('/product_image/{product_image}',   'destroy')->name('product_image.destroy')->middleware('can:AnyAction');
    });

    //// Product Image Gallery Routes (new) - ✅ هذه هي الـ Route الوحيدة لحذف الصور ////
    Route::get('/product/{product}/images',         [ProductImageController::class, 'manageImages'])->name('product.images.index');
    Route::post('/product/{product}/images',        [ProductImageController::class, 'uploadImages'])->name('product.images.store');
    Route::post('/product-image/{image}/set-cover', [ProductImageController::class, 'setCover'])->name('product.images.cover');
    Route::post('/product-image/sort',              [ProductImageController::class, 'sort'])->name('product.images.sort');
    Route::delete('/product-image/{image}',         [ProductImageController::class, 'destroyImage'])->name('product.images.destroy');

    //// Product Rating Route ////
    Route::resource('product_rating', ProductRatingController::class)->except(['show']);

    //// Offer Route ////
    Route::resource('offer', OfferController::class);

    //// Banner Routes ////
    Route::resource('banner_home',   BannerHomeController::class)->except(['show']);
    Route::resource('banner_side',   BannerSideController::class)->except(['show']);
    Route::resource('banner_bottom', BannerBottomController::class)->except(['show']);

    //// User Route ////
    Route::resource('user', UserController::class)->except(['show']);

    //// Shipping City Route ////
    Route::resource('shipping_city', ShippingCityController::class)->except(['show']);
    Route::get('/shipping_city/export',  [ShippingCityController::class, 'export'])->name('shipping_city.export');
    Route::post('/shipping_city/import', [ShippingCityController::class, 'import'])->name('shipping_city.import');

    //// Offer Rating Route ////
    Route::resource('offer_rating', OfferRatingController::class)->except(['show']);

    //// Order Route ////
    Route::controller(OrderController::class)->group(function () {
        Route::get('/order',              'index')->name('order.index');
        Route::get('/order/{order}',      'show')->name('order.show');
        Route::get('/order/{order}/edit', 'edit')->name('order.edit');
        Route::put('/order/{order}',      'update')->name('order.update');
        Route::get('/order/payment/{id}', 'showPayment')->name('order.payment');
    });

    //// Report Route ////
    Route::get('/report',  [ReportController::class, 'index'])->name('report.index');
    Route::post('/report', [ReportController::class, 'store'])->name('report.store');

    //// Blog Route ////
    Route::resource('blog', BlogController::class);

});

require __DIR__.'/auth.php';