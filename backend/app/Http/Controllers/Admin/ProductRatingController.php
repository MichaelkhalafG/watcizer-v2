<?php

namespace App\Http\Controllers\Admin;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Models\ProductRating;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class ProductRatingController extends Controller
{
    public function index()
    {
        $product_rating = ProductRating::all();
        return view('Dashboard.product_rating.index' , compact('product_rating'));
    }

    public function create()
    {
        $product = Product::withTranslation()->where('active' , 1)->get(['id' , 'wa_code']);

        return view('Dashboard.product_rating.create' , compact('product'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'rating'     => 'required|numeric|min:1|max:5',
            'comment'    => 'nullable|string',
        ]);

        ProductRating::create([
            'product_id' => $request->product_id,
            'rating'     => $request->rating,
            'comment'    => $request->comment,
            'user_id'    => auth()->user()->id,
            'date'       => date('Y-m-d H:i'),
        ]);

        $product = Product::with('product_rating')->where('id' , $request->product_id)->first();

        $averageRating         = $product->product_rating()->avg('rating');
        $product->average_rate = $averageRating;
        $product->save();

        Cache::forget('AllProductRating');

        return redirect(route('product_rating.index'))->with('success' , trans('messages.add'));
    }

    public function edit(ProductRating $product_rating)
    {
        $product = Product::withTranslation()->where('active' , 1)->get(['id' , 'wa_code']);
        return view('Dashboard.product_rating.edit' , compact('product_rating' , 'product'));
    }

    public function update(Request $request, ProductRating $productRating)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'rating'     => 'required|numeric|min:1|max:5',
            'comment'    => 'nullable|string',
        ]);

        $productRating->product_id = $request->product_id;
        $productRating->rating     = $request->rating;
        $productRating->comment    = $request->comment;

        $productRating->save();

        $product = Product::with('product_rating')->where('id' , $request->product_id)->first();

        $averageRating         = $product->product_rating()->avg('rating');
        $product->average_rate = $averageRating;
        $product->save();

        Cache::forget('AllProductRating');

        return redirect(route('product_rating.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(ProductRating $productRating)
    {
        $productRating->delete();

        $product = Product::with('product_rating')->where('id' , $productRating->product_id)->first();

        $averageRating         = $product->product_rating()->avg('rating');
        $product->average_rate = $averageRating;
        $product->save();

        Cache::forget('AllProductRating');

        return back()->with('success' , trans('messages.delete'));
    }
}
