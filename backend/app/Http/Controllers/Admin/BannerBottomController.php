<?php

namespace App\Http\Controllers\Admin;

use App\Models\Offer;
use App\Models\BannerBottom;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class BannerBottomController extends Controller
{
    public function index()
    {
        $banner_bottom = BannerBottom::all();

        return view('Dashboard.banner_bottom.index' , compact('banner_bottom'));
    }

    public function create()
    {
        $offer = Offer::withTranslation()->get(['id' , 'wa_code']);

        return view('Dashboard.banner_bottom.create' , compact('offer'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'image'        => 'required',
            'image.*'      => 'image|mimes:png,jpg,webp,gif|max:5120',
            'offer_id'     => 'nullable|exists:offers,id',
        ]);

        foreach ($request->file('image') as $img)
        {
            // Banners: max 1920x800, WebP q85. Skip (and log) any image that
            // fails to process rather than 500-ing the whole batch.
            $name = $this->tryProcessImage($img, 'Banner_Bottom', [
                'max_width'  => 1920,
                'max_height' => 800,
                'quality'    => 85,
            ]);
            if ($name === null) {
                continue;
            }
            BannerBottom::create([
                'image'    => $name,
                'offer_id' => $request->offer_id,
            ]);
        }
        Cache::forget('AllBannerBottom');

        return redirect(route('banner_bottom.index'))->with('success' , trans('messages.add'));
    }

    public function edit(BannerBottom $banner_bottom)
    {
        $offer = Offer::withTranslation()->get(['id' , 'wa_code']);

        return view('Dashboard.banner_bottom.edit' , compact('banner_bottom' , 'offer'));
    }

    public function update(Request $request, BannerBottom $banner_bottom)
    {
        $request->validate([
            'image'        => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
            'offer_id'   => 'nullable|exists:offers,id',
        ]);


        if ($image = $request->file('image')) {
            $banner_bottom->image = $this->processImageOrFail($image, 'Banner_Bottom', [
                'max_width'  => 1920,
                'max_height' => 800,
                'quality'    => 85,
            ], $banner_bottom->image);
        }
        $banner_bottom->offer_id  = $request->offer_id;

        $banner_bottom->save();

        Cache::forget('AllBannerBottom');

        return redirect(route('banner_bottom.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(BannerBottom $banner_bottom)
    {
        $oldImage = public_path('Uploads_Images/Banner_Bottom/' . $banner_bottom->image);
        if (file_exists($oldImage))
        {
            unlink($oldImage);
        }
        $banner_bottom->delete();

        Cache::forget('AllBannerBottom');

        return back()->with('success' , trans('messages.delete'));
    }
}
