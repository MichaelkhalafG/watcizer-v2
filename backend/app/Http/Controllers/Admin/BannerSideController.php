<?php

namespace App\Http\Controllers\Admin;

use App\Models\BannerSide;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;

class BannerSideController extends Controller
{
    public function index()
    {
        $banner_side = BannerSide::all();

        return view('Dashboard.banner_side.index' , compact('banner_side'));
    }

    public function create()
    {
        $offer = Offer::withTranslation()->get(['id' , 'wa_code']);

        return view('Dashboard.banner_side.create' , compact('offer'));
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
            BannerSide::create([

                $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.' . 'webp',
                $manager = new ImageManager(new Driver()),
                $img     = $manager->read($img),
                $img->toWebp()->save(public_path('/Uploads_Images/Banner_Side/' . $NewName)),

                'image'      => $NewName,
                'offer_id'  => $request->offer_id,
            ]);
        }
        Cache::forget('AllBannerSide');

        return redirect(route('banner_side.index'))->with('success' , trans('messages.add'));
    }

    public function edit(BannerSide $banner_side)
    {
        $offer = Offer::withTranslation()->get(['id' , 'wa_code']);

        return view('Dashboard.banner_side.edit' , compact('banner_side' , 'offer'));
    }

    public function update(Request $request, BannerSide $banner_side)
    {
        $request->validate([
            'image'        => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
            'offer_id'     => 'nullable|exists:offers,id',
        ]);


        if ($image = $request->file('image')) {
            $oldImage = public_path('Uploads_Images/Banner_Side/' . $banner_side->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.' . 'webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Banner_Side/' . $NewName));

            $banner_side->image       = $NewName;
        }
        $banner_side->offer_id  = $request->offer_id;

        $banner_side->save();

        Cache::forget('AllBannerSide');

        return redirect(route('banner_side.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(BannerSide $banner_side)
    {
        $oldImage = public_path('Uploads_Images/Banner_Side/' . $banner_side->image);
        if (file_exists($oldImage))
        {
            unlink($oldImage);
        }
        $banner_side->delete();

        Cache::forget('AllBannerSide');

        return back()->with('success' , trans('messages.delete'));
    }
}
