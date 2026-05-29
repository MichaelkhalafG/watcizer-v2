<?php

namespace App\Http\Controllers\Admin;

use App\Models\CategoryType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exports\CategoryTypeExport;
use App\Imports\CategoryTypeImport;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;
use Maatwebsite\Excel\Validators\ValidationException;

class CategoryTypeController extends Controller
{
    public function index()
    {
        $category_type = CategoryType::all();
        return view('Dashboard.category_type.index' , compact('category_type'));
    }

    public function create()
    {
        return view('Dashboard.category_type.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_type_name.en' => 'required|string|min:2|max:255|unique:category_type_translations,category_type_name',
            'category_type_name.ar' => 'required|string|min:2|max:255|unique:category_type_translations,category_type_name',
            'image'                 => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',

        ]);

        $category_type = new CategoryType;

        $category_type->translateOrNew('ar')->category_type_name = $request['category_type_name']['ar'];
        $category_type->translateOrNew('en')->category_type_name = $request['category_type_name']['en'];

        if ($image = $request->file('image')) {
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Category_type/' . $NewName));

            $category_type->image = $NewName;
        } else {
            unset($category_type->image);
        }

        $category_type->save();

        Cache::forget('AllCategoryType');

        return redirect(route('category_type.index'))->with('success' , trans('messages.add'));
    }

    public function edit(CategoryType $category_type)
    {
        return view('Dashboard.category_type.edit' , compact('category_type'));
    }

    public function update(Request $request, CategoryType $category_type)
    {
        $request->validate([
            'category_type_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('category_type_translations','category_type_name')->ignore($category_type->translate('en')->id)],
            'category_type_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('category_type_translations','category_type_name')->ignore($category_type->translate('ar')->id)],
            'image'            => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
        ]);

        $category_type->translateOrNew('ar')->category_type_name = $request['category_type_name']['ar'];
        $category_type->translateOrNew('en')->category_type_name = $request['category_type_name']['en'];

        if ($image = $request->file('image')) {

            if ($category_type->image) {
                $oldImage = public_path('Uploads_Images/Category_type/' . $category_type->image);
                if (file_exists($oldImage))
                {
                    unlink($oldImage);
                }
            }

            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Category_type/' . $NewName));

            $category_type->image = $NewName;
        } else {
            unset($category_type->image);
        }

        $category_type->save();

        Cache::forget('AllCategoryType');

        return redirect(route('category_type.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(CategoryType $category_type)
    {
        $category_type_count = $category_type->withCount('product')->findOrFail($category_type->id);
        if ($category_type_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        if ($category_type->image) {
            $oldImage = public_path('Uploads_Images/Category_type/' . $category_type->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
        }

        $category_type->delete();

        Cache::forget('AllCategoryType');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new CategoryTypeExport, 'CategoryType.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new CategoryTypeImport, $request->file('import'));

            Cache::forget('AllCategoryType');

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
