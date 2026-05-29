<?php

namespace App\Http\Controllers\Admin;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Exports\CategoryExport;
use App\Imports\CategoryImport;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Maatwebsite\Excel\Validators\ValidationException;

class CategoryController extends Controller
{
    public function index()
    {
        $category = Category::all();
        return view('Dashboard.category.index' , compact('category'));
    }

    public function create()
    {
        return view('Dashboard.category.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'category_name.en' => 'required|min:2|max:255|unique:category_translations,category_name',
            'category_name.ar' => 'required|min:2|max:255|unique:category_translations,category_name',
            'color_value'      => 'required|min:2|max:255|hex_color',
            'category_image'   => 'required|image|mimes:png,jpg,webp,gif|max:5120',
        ]);

        $category = new Category;

        $category->translateOrNew('ar')->category_name = $request['category_name']['ar'];
        $category->translateOrNew('en')->category_name = $request['category_name']['en'];
        $category->color_value                                 = $request['color_value'];

        $image   = $request->file('category_image');
        $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
        $manager = new ImageManager(new Driver());
        $img     = $manager->read($image);
        $img->toWebp()->save(public_path('/Uploads_Images/Category/' . $NewName));

        $category->category_image = $NewName;

        $category->save();

        Cache::forget('AllCategory');

        return redirect(route('category.index'))->with('success' , trans('messages.add'));
    }

    public function edit(Category $category)
    {
        return view('Dashboard.category.edit' , compact('category'));
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'category_name.en' => ['required' , 'min:2' , 'max:255' , Rule::unique('category_translations','category_name')->ignore($category->translate('en')->id)],
            'category_name.ar' => ['required' , 'min:2' , 'max:255' , Rule::unique('category_translations','category_name')->ignore($category->translate('ar')->id)],
            'color_value'      => 'required|min:2|max:255|hex_color',
            'category_image'   => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
        ]);

        $category->translateOrNew('ar')->category_name = $request['category_name']['ar'];
        $category->translateOrNew('en')->category_name = $request['category_name']['en'];
        $category->color_value                                 = $request['color_value'];

        if ($image = $request->file('category_image')) {
            $oldImage = public_path('Uploads_Images/Category/' . $category->category_image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
            $NewName = time() . '_' . date('Y-m-d_')  . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Category/' . $NewName));

            $category->category_image = $NewName;
        }

        $category->save();

        Cache::forget('AllCategory');

        return redirect(route('category.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Category $category)
    {
        $oldImage = public_path('Uploads_Images/Category/' . $category->category_image);
        if (file_exists($oldImage))
        {
            unlink($oldImage);
        }
        $category->delete();

        Cache::forget('AllCategory');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new CategoryExport, 'Category.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            $file     = $request->file('import');
            $filePath = $file->storeAs('temp', uniqid() . '.' . $file->getClientOriginalExtension());

            Excel::import(new CategoryImport(storage_path('app/' . $filePath)) , storage_path('app/' . $filePath));

            Storage::delete($filePath);

            Cache::forget('AllCategory');

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
