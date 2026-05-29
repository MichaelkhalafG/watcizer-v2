<?php

namespace App\Http\Controllers\Admin;

use App\Models\Offer;
use App\Models\Product;
use App\Models\BannerSide;
use App\Models\BannerBottom;
use App\Models\CategoryType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;

class OfferController extends Controller
{
    public function index()
    {
        $offer = Offer::all();
        return view('Dashboard.offer.index' , compact('offer'));
    }

    public function create()
    {
        $product       = Product::withTranslation()->where('active' , 1)->get(['id' , 'wa_code']);
        $category_type = CategoryType::withTranslation()->get(['id']);

        return view('Dashboard.offer.create' , compact('product' , 'category_type'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'offer_name.en'             => 'required|string|min:2|max:255',
            'offer_name.ar'             => 'required|string|min:2|max:255',
            'main_product_id'           => 'required|integer|exists:products,id',
            'category_type_id'          => 'required|integer|exists:category_types,id',
            'gift_product_ids'          => 'required|array',
            'gift_product_ids.*'        => 'integer|exists:products,id',
            'selling_price'             => 'required|numeric|min:0',
            'sale_price_after_discount' => 'required|numeric|min:0',
            'stock'                     => 'required|numeric|min:0',
            'in_season'                 => 'required|in:yes,no',
            'image'                     => 'required|image|mimes:png,jpg,webp,gif|max:5120',
            'wa_code'                   => 'required|unique:products,wa_code|unique:offers,wa_code|string|min:2|max:255',
            'long_description.ar'       => 'required|string',
            'long_description.en'       => 'required|string',
            'short_description.ar'      => 'required|string',
            'short_description.en'      => 'required|string',
        ]);

        $offer = new Offer;

        $offer->translateOrNew('ar')->offer_name  = $request['offer_name']['ar'];
        $offer->translateOrNew('en')->offer_name  = $request['offer_name']['en'];
        $offer->main_product_id                           = $request->main_product_id;
        $offer->gift_product_ids                          = $request->gift_product_ids;
        $offer->selling_price                             = $request['selling_price'];
        $offer->sale_price_after_discount                 = $request['sale_price_after_discount'];        $offer->stock                                     = $request->stock;
        $offer->category_type_id                          = $request->category_type_id;
        $offer->wa_code                                   = $request['wa_code'];
        $offer->in_season                                 = $request['in_season'];
        $offer->translateOrNew('ar')->short_description = $request['short_description']['ar'];
        $offer->translateOrNew('en')->short_description = $request['short_description']['en'];
        $offer->translateOrNew('ar')->long_description  = $request['long_description']['ar'];
        $offer->translateOrNew('en')->long_description  = $request['long_description']['en'];


        $image   = $request->file('image');
        $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
        $manager = new ImageManager(new Driver());
        $img     = $manager->read($image);
        $img->toWebp()->save(public_path('/Uploads_Images/Offer/' . $NewName));

        $offer->image = $NewName;

        $offer->save();

        Cache::forget('AllOffer');

        return redirect(route('offer.index'))->with('success' , trans('messages.add'));
    }

    public function show(Offer $offer)
    {
        return view('dashboard.offer.show' , compact('offer'));
    }

    public function edit(Offer $offer)
    {
        $product = Product::withTranslation()->where('active' , 1)->get(['id' , 'wa_code']);
        $category_type = CategoryType::withTranslation()->get(['id']);

        return view('Dashboard.offer.edit' , compact('offer' , 'product' , 'category_type'));
    }

    public function update(Request $request, Offer $offer)
    {
        $request->validate([
            'offer_name.en'             => 'required|string|min:2|max:255',
            'offer_name.ar'             => 'required|string|min:2|max:255',
            'main_product_id'           => 'required|integer|exists:products,id',
            'category_type_id'          => 'required|integer|exists:category_types,id',
            'gift_product_ids'          => 'required|array',
            'gift_product_ids.*'        => 'integer|exists:products,id',
            'selling_price'             => 'required|numeric|min:0',
            'sale_price_after_discount' => 'required|numeric|min:0',
            'stock'                     => 'required|numeric|min:0',
            'in_season'                 => 'required|in:yes,no',
            'image'                     => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
            'wa_code'                   => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('offers','wa_code')->ignore($offer->id)],
            'long_description.ar'       => 'required|string',
            'long_description.en'       => 'required|string',
            'short_description.ar'      => 'required|string',
            'short_description.en'      => 'required|string',
        ]);

        $offer->translateOrNew('ar')->offer_name = $request['offer_name']['ar'];
        $offer->translateOrNew('en')->offer_name = $request['offer_name']['en'];
        $offer->main_product_id                          = $request->main_product_id;
        $offer->gift_product_ids                         = $request->gift_product_ids;
        $offer->selling_price                            = $request['selling_price'];
        $offer->sale_price_after_discount                = $request['sale_price_after_discount'];        $offer->stock                                    = $request->stock;
        $offer->category_type_id                         = $request->category_type_id;
        $offer->wa_code                                  = $request['wa_code'];
        $offer->in_season                                = $request['in_season'];
        $offer->translateOrNew('ar')->short_description = $request['short_description']['ar'];
        $offer->translateOrNew('en')->short_description = $request['short_description']['en'];
        $offer->translateOrNew('ar')->long_description  = $request['long_description']['ar'];
        $offer->translateOrNew('en')->long_description  = $request['long_description']['en'];

        if ($image = $request->file('image')) {
            $oldImage = public_path('Uploads_Images/Offer/' . $offer->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
            $NewName = time() . '_' . date('Y-m-d_')  . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Offer/' . $NewName));

            $offer->image = $NewName;
        }

        $offer->save();

        Cache::forget('AllOffer');

        return redirect(route('offer.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Offer $offer)
    {
        $offer_count = $offer->withCount('order_items')->findOrFail($offer->id);
        if ($offer_count->order_items_count > 0) {
            return back()->with('error' , trans('messages.undelete_order'));
        }

        $banner_side = BannerSide::where('offer_id' , $offer->id)->get();
        foreach ($banner_side as $item) {
            if ($offer->image && file_exists(public_path('Uploads_Images/Banner_Side/' . $item->image))) {
                unlink(public_path('Uploads_Images/Banner_Side/' . $item->image));
            }
            $item->delete();
        }

        $banner_bottom = BannerBottom::where('offer_id' , $offer->id)->get();
        foreach ($banner_bottom as $item) {
            if ($offer->image && file_exists(public_path('Uploads_Images/Banner_Bottom/' . $item->image))) {
                unlink(public_path('Uploads_Images/Banner_Bottom/' . $item->image));
            }
            $item->delete();
        }

        $banner_home = BannerBottom::where('offer_id' , $offer->id)->get();
        foreach ($banner_home as $item) {
            if ($offer->image && file_exists(public_path('Uploads_Images/Banner_home/' . $item->image))) {
                unlink(public_path('Uploads_Images/Banner_home/' . $item->image));
            }
            $item->delete();
        }

        $oldImage = public_path('Uploads_Images/Offer/' . $offer->image);
        if (file_exists($oldImage))
        {
            unlink($oldImage);
        }
        $offer->delete();

        Cache::forget('AllOffer');

        return back()->with('success' , trans('messages.delete'));
    }
}
