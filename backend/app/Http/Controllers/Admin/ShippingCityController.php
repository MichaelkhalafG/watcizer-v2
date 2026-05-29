<?php

namespace App\Http\Controllers\Admin;

use App\Models\ShippingCity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exports\ShippingCityExport;
use App\Imports\ShippingCityImport;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class ShippingCityController extends Controller
{
    public function index()
    {
        $shipping_city = ShippingCity::all();
        return view('Dashboard.shipping_city.index' , compact('shipping_city'));
    }

    public function create()
    {
        return view('Dashboard.shipping_city.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'city_name.en'  => 'required|string|min:2|max:255|unique:shipping_city_translations,city_name',
            'city_name.ar'  => 'required|string|min:2|max:255|unique:shipping_city_translations,city_name',
            'shipping_cost' => 'required|numeric|min:0',
        ]);

        $shipping_city = new ShippingCity;

        $shipping_city->translateOrNew('ar')->city_name = $request['city_name']['ar'];
        $shipping_city->translateOrNew('en')->city_name = $request['city_name']['en'];
        $shipping_city->shipping_cost                           = $request['shipping_cost'];

        $shipping_city->save();

        Cache::forget('ShowShippingCity');

        return redirect(route('shipping_city.index'))->with('success' , trans('messages.add'));
    }

    public function edit(ShippingCity $shipping_city)
    {
        return view('Dashboard.shipping_city.edit' , compact('shipping_city'));
    }

    public function update(Request $request, ShippingCity $shipping_city)
    {
        $request->validate([
            'city_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('shipping_city_translations','city_name')->ignore($shipping_city->translate('en')->id)],
            'city_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('shipping_city_translations','city_name')->ignore($shipping_city->translate('ar')->id)],
            'shipping_cost' => 'required|numeric|min:0',
        ]);

        $shipping_city->translateOrNew('ar')->city_name = $request['city_name']['ar'];
        $shipping_city->translateOrNew('en')->city_name = $request['city_name']['en'];
        $shipping_city->shipping_cost                           = $request['shipping_cost'];

        $shipping_city->save();

        Cache::forget('ShowShippingCity');

        return redirect(route('shipping_city.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(ShippingCity $shipping_city)
    {
        $shipping_city_count = $shipping_city->withCount('address')->findOrFail($shipping_city->id);
        if ($shipping_city_count->address_count > 0) {
            return back()->with('error' , trans('messages.undelete_shipping_city'));
        }

        $shipping_city->delete();

        Cache::forget('ShowShippingCity');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new ShippingCityExport, 'shipping_city.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new ShippingCityImport, $request->file('import'));

            Cache::forget('ShowShippingCity');

            return back()->with('success' , trans('messages.import_mes'));

        } catch (ValidationException $e) {
            $failures = $e->failures();

            $errorMessages = [];
            foreach ($failures as $failure) {
                $errorMessages[] = "Row {$failure->row()} : " . implode(', ', $failure->errors());
            }

            return back()->with('validationErrors', $errorMessages);
        }
    }
}
