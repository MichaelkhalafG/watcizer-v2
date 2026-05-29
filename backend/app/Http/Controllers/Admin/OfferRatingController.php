<?php

namespace App\Http\Controllers\Admin;

use App\Models\Offer;
use App\Models\OfferRating;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class OfferRatingController extends Controller
{
    public function index()
    {
        $offer_rating = OfferRating::all();
        return view('Dashboard.offer_rating.index' , compact('offer_rating'));
    }

    public function create()
    {
        $offer = Offer::all(['id' , 'main_product_id']);

        return view('Dashboard.offer_rating.create' , compact('offer'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'offer_id' => 'required|integer|exists:offers,id',
            'rating'   => 'required|numeric|min:1|max:5',
            'comment'  => 'nullable|string',
        ]);

        OfferRating::create([
            'offer_id' => $request->offer_id,
            'rating'   => $request->rating,
            'comment'  => $request->comment,
            'user_id'  => auth()->user()->id,
            'date'     => date('Y-m-d H:i'),
        ]);

        $offer = Offer::with('offer_rating')->where('id' , $request->offer_id)->first();

        $averageRating       = $offer->offer_rating()->avg('rating');
        $offer->average_rate = $averageRating;
        $offer->save();

        Cache::forget('AllOfferRating');

        return redirect(route('offer_rating.index'))->with('success' , trans('messages.add'));
    }

    public function edit(OfferRating $offer_rating)
    {
        $offer = Offer::all(['id' , 'main_product_id']);
        return view('Dashboard.offer_rating.edit' , compact('offer_rating' , 'offer'));
    }

    public function update(Request $request, OfferRating $offer_rating)
    {
        $request->validate([
            'offer_id' => 'required|integer|exists:offers,id',
            'rating'     => 'required|numeric|min:1|max:5',
            'comment'    => 'nullable|string',
        ]);

        $offer_rating->offer_id = $request->offer_id;
        $offer_rating->rating   = $request->rating;
        $offer_rating->comment  = $request->comment;

        $offer_rating->save();

        $offer = Offer::with('offer_rating')->where('id' , $request->offer_id)->first();

        $averageRating       = $offer->offer_rating()->avg('rating');
        $offer->average_rate = $averageRating;
        $offer->save();

        Cache::forget('AllOfferRating');

        return redirect(route('offer_rating.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(OfferRating $offer_rating)
    {
        $offer_rating->delete();

        $offer = Offer::with('offer_rating')->where('id' , $offer_rating->offer_id)->first();

        $averageRating       = $offer->offer_rating()->avg('rating');
        $offer->average_rate = $averageRating;
        $offer->save();

        Cache::forget('AllOfferRating');

        return back()->with('success' , trans('messages.delete'));
    }
}
