<?php

namespace App\Http\Controllers\Admin;

use App\Models\Offer;
use App\Models\BannerBottom;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;

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
            BannerBottom::create([

                $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.' . 'webp',
                $manager = new ImageManager(new Driver()),
                $img     = $manager->read($img),
                $img->toWebp()->save(public_path('/Uploads_Images/Banner_Bottom/' . $NewName)),

                'image'      => $NewName,
                'offer_id'   => $request->offer_id,
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
            $oldImage = public_path('Uploads_Images/Banner_Bottom/' . $banner_bottom->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.' . 'webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Banner_Bottom/' . $NewName));

            $banner_bottom->image       = $NewName;
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
