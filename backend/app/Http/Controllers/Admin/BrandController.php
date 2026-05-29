<?php

namespace App\Http\Controllers\Admin;

use App\Models\Brand;
use App\Exports\BrandExport;
use App\Imports\BrandImport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;
use Maatwebsite\Excel\Validators\ValidationException;

class BrandController extends Controller
{
    public function index()
    {
        $brand = Brand::all();
        return view('Dashboard.brand.index' , compact('brand'));
    }

    public function create()
    {
        return view('Dashboard.brand.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'brand_name.en' => 'required|string|min:2|max:255|unique:brand_translations,brand_name',
            'brand_name.ar' => 'required|string|min:2|max:255|unique:brand_translations,brand_name',
            'image'         => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
        ]);

        $brand = new Brand;

        $brand->translateOrNew('ar')->brand_name = $request['brand_name']['ar'];
        $brand->translateOrNew('en')->brand_name = $request['brand_name']['en'];

        if ($image = $request->file('image')) {
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Brand/' . $NewName));

            $brand->image = $NewName;
        } else {
            unset($brand->image);
        }

        $brand->save();

        Cache::forget('AllBrand');

        return redirect(route('brand.index'))->with('success' , trans('messages.add'));
    }

    public function edit(Brand $brand)
    {
        return view('Dashboard.brand.edit' , compact('brand'));
    }

    public function update(Request $request, Brand $brand)
    {
        $request->validate([
            'brand_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('brand_translations','brand_name')->ignore($brand->translate('en')->id)],
            'brand_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('brand_translations','brand_name')->ignore($brand->translate('ar')->id)],
            'image'         => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
        ]);

        $brand->translateOrNew('ar')->brand_name = $request['brand_name']['ar'];
        $brand->translateOrNew('en')->brand_name = $request['brand_name']['en'];

        if ($image = $request->file('image')) {

            if ($brand->image) {
                $oldImage = public_path('Uploads_Images/Brand/' . $brand->image);
                if (file_exists($oldImage))
                {
                    unlink($oldImage);
                }
            }

            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Brand/' . $NewName));

            $brand->image = $NewName;
        } else {
            unset($brand->image);
        }

        $brand->save();

        Cache::forget('AllBrand');

        return redirect(route('brand.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Brand $brand)
    {
        $brand_count = $brand->withCount('product')->findOrFail($brand->id);
        if ($brand_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        if ($brand->image) {
            $oldImage = public_path('Uploads_Images/Brand/' . $brand->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
        }

        $brand->delete();

        Cache::forget('AllBrand');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new BrandExport, 'Brand.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new BrandImport, $request->file('import'));

            Cache::forget('AllBrand');

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
