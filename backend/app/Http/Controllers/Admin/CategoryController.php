<?php

namespace App\Http\Controllers\Admin;

use App\Models\Category;
use Illuminate\Http\Request;
use App\Exports\CategoryExport;
use App\Imports\CategoryImport;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use App\Services\ImageService;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Validators\ValidationException;

class CategoryController extends Controller
{
    public function index()
    {
        $category = Category::with('parent')->orderBy('level')->orderBy('sort_order')->orderBy('id')->get();
        return view('Dashboard.category.index', compact('category'));
    }

    public function create()
    {
        // Parents to choose from (any existing category can be a parent).
        $parents = Category::with('parent')->orderBy('level')->orderBy('sort_order')->orderBy('id')->get();
        return view('Dashboard.category.create', compact('parents'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name.en'        => 'required|min:2|max:255',
            'name.ar'        => 'required|min:2|max:255',
            'description.en' => 'nullable|max:2000',
            'description.ar' => 'nullable|max:2000',
            'parent_id'      => 'nullable|exists:categories,id',
            'slug'           => 'nullable|min:2|max:255|unique:categories,slug',
            'sort_order'     => 'nullable|integer|min:0',
            'image'          => 'nullable|image|mimes:png,jpg,jpeg,webp,gif|max:3072',
        ]);

        $category = new Category;

        $category->translateOrNew('en')->name        = $request->input('name.en');
        $category->translateOrNew('ar')->name        = $request->input('name.ar');
        $category->translateOrNew('en')->description = $request->input('description.en');
        $category->translateOrNew('ar')->description = $request->input('description.ar');

        $parentId = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;
        $category->parent_id  = $parentId;
        $category->level      = $parentId ? (optional(Category::find($parentId))->level ?? 0) + 1 : 1;
        $category->sort_order = (int) $request->input('sort_order', 0);
        $category->is_active  = $request->boolean('is_active');

        if ($request->filled('slug')) {
            $category->slug = Str::slug($request->input('slug'));
        }

        if ($image = $request->file('image')) {
            // Category image: max 600x600, WebP q80.
            $category->image = (new ImageService)->process($image, 'Category', [
                'max_width'  => 600,
                'max_height' => 600,
                'quality'    => 80,
            ]);
        }

        $category->save();

        Cache::forget('AllCategory');

        return redirect(route('category.index'))->with('success', trans('messages.add'));
    }

    public function edit(Category $category)
    {
        // Any category except itself may be selected as the parent.
        $parents = Category::with('parent')
            ->where('id', '!=', $category->id)
            ->orderBy('level')->orderBy('sort_order')->orderBy('id')->get();

        return view('Dashboard.category.edit', compact('category', 'parents'));
    }

    public function update(Request $request, Category $category)
    {
        $request->validate([
            'name.en'        => 'required|min:2|max:255',
            'name.ar'        => 'required|min:2|max:255',
            'description.en' => 'nullable|max:2000',
            'description.ar' => 'nullable|max:2000',
            'parent_id'      => 'nullable|exists:categories,id',
            'slug'           => ['nullable', 'min:2', 'max:255', Rule::unique('categories', 'slug')->ignore($category->id)],
            'sort_order'     => 'nullable|integer|min:0',
            'image'          => 'nullable|image|mimes:png,jpg,jpeg,webp,gif|max:3072',
        ]);

        $category->translateOrNew('en')->name        = $request->input('name.en');
        $category->translateOrNew('ar')->name        = $request->input('name.ar');
        $category->translateOrNew('en')->description = $request->input('description.en');
        $category->translateOrNew('ar')->description = $request->input('description.ar');

        // Guard against selecting itself as its own parent.
        $parentId = $request->filled('parent_id') ? (int) $request->input('parent_id') : null;
        if ($parentId === $category->id) {
            $parentId = $category->parent_id;
        }
        $category->parent_id  = $parentId;
        $category->level      = $parentId ? (optional(Category::find($parentId))->level ?? 0) + 1 : 1;
        $category->sort_order = (int) $request->input('sort_order', 0);
        $category->is_active  = $request->boolean('is_active');

        if ($request->filled('slug')) {
            $category->slug = Str::slug($request->input('slug'));
        }

        if ($image = $request->file('image')) {
            $oldImage = public_path('Uploads_Images/Category/' . $category->image);
            if ($category->image && file_exists($oldImage)) {
                unlink($oldImage);
            }
            // Category image: max 600x600, WebP q80.
            $category->image = (new ImageService)->process($image, 'Category', [
                'max_width'  => 600,
                'max_height' => 600,
                'quality'    => 80,
            ]);
        }

        $category->save();

        Cache::forget('AllCategory');

        return redirect(route('category.index'))->with('success', trans('messages.edit'));
    }

    public function destroy(Category $category)
    {
        if ($category->image) {
            $oldImage = public_path('Uploads_Images/Category/' . $category->image);
            if (file_exists($oldImage)) {
                unlink($oldImage);
            }
        }
        $category->delete();

        Cache::forget('AllCategory');

        return back()->with('success', trans('messages.delete'));
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

            Excel::import(new CategoryImport(storage_path('app/' . $filePath)), storage_path('app/' . $filePath));

            Storage::delete($filePath);

            Cache::forget('AllCategory');

            return back()->with('success', trans('messages.import_mes'));

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
