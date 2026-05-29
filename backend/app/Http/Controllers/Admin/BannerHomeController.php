<?php

namespace App\Http\Controllers\Admin;

use App\Models\Offer;
use App\Models\BannerHome;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;

class BannerHomeController extends Controller
{
    public function index()
    {
        $banner_home = BannerHome::all();

        return view('Dashboard.banner_home.index' , compact('banner_home'));
    }

    public function create()
    {
        $offer = Offer::withTranslation()->get(['id' , 'wa_code']);

        return view('Dashboard.banner_home.create', compact('offer'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'image'        => 'required',
            'image.*'      => 'image|mimes:png,jpg,webp,gif|max:5120',
            'type_show'    => 'required|in:mob,pc',
            'offer_id'     => 'nullable|exists:offers,id',
        ]);

        foreach ($request->file('image') as $img)
        {
            BannerHome::create([

                $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.' . 'webp',
                $manager = new ImageManager(new Driver()),
                $img     = $manager->read($img),
                $img->toWebp()->save(public_path('/Uploads_Images/Banner_home/' . $NewName)),

                'image'     => $NewName,
                'type_show' => $request->type_show,
                'offer_id'  => $request->offer_id,
            ]);
        }
        Cache::forget('AllBannerHome');

        return redirect(route('banner_home.index'))->with('success' , trans('messages.add'));
    }

    public function edit(BannerHome $banner_home)
    {
        $offer = Offer::withTranslation()->get(['id' , 'wa_code']);

        return view('Dashboard.banner_home.edit' , compact('banner_home', 'offer'));
    }

    public function update(Request $request, BannerHome $banner_home)
    {
        $request->validate([
            'image'     => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
            'type_show' => 'required|in:mob,pc',
            'offer_id'  => 'nullable|exists:offers,id',
        ]);


        if ($image = $request->file('image')) {
            $oldImage = public_path('Uploads_Images/Banner_home/' . $banner_home->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.' . 'webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Banner_home/' . $NewName));

            $banner_home->image       = $NewName;
        }
        $banner_home->type_show   = $request->type_show;
        $banner_home->offer_id    = $request->offer_id;

        $banner_home->save();

        Cache::forget('AllBannerHome');

        return redirect(route('banner_home.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(BannerHome $banner_home)
    {
        $oldImage = public_path('Uploads_Images/Banner_home/' . $banner_home->image);
        if (file_exists($oldImage))
        {
            unlink($oldImage);
        }
        $banner_home->delete();

        Cache::forget('AllBannerHome');

        return back()->with('success' , trans('messages.delete'));
    }
}
