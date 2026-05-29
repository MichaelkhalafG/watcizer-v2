<?php

namespace App\Http\Controllers\Admin;

use App\Models\SubType;
use Illuminate\Http\Request;
use App\Exports\SubTypeExport;
use App\Imports\SubTypeImport;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;
use Maatwebsite\Excel\Validators\ValidationException;

class SubTypeController extends Controller
{
    public function index()
    {
        $sub_type = SubType::all();
        return view('Dashboard.sub_type.index' , compact('sub_type'));
    }

    public function create()
    {
        return view('Dashboard.sub_type.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'sub_type_name.en' => 'required|string|min:2|max:255|unique:sub_type_translations,sub_type_name',
            'sub_type_name.ar' => 'required|string|min:2|max:255|unique:sub_type_translations,sub_type_name',
            'image'            => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
        ]);

        $sub_type = new SubType;

        $sub_type->translateOrNew('ar')->sub_type_name = $request['sub_type_name']['ar'];
        $sub_type->translateOrNew('en')->sub_type_name = $request['sub_type_name']['en'];

        if ($image = $request->file('image')) {
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Sub_type/' . $NewName));

            $sub_type->image = $NewName;
        } else {
            unset($sub_type->image);
        }

        $sub_type->save();

        Cache::forget('AllSubType');

        return redirect(route('sub_type.index'))->with('success' , trans('messages.add'));
    }

    public function edit(SubType $sub_type)
    {
        return view('Dashboard.sub_type.edit' , compact('sub_type'));
    }

    public function update(Request $request, SubType $sub_type)
    {
        $request->validate([
            'sub_type_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('sub_type_translations','sub_type_name')->ignore($sub_type->translate('en')->id)],
            'sub_type_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('sub_type_translations','sub_type_name')->ignore($sub_type->translate('ar')->id)],
            'image'            => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',
        ]);

        $sub_type->translateOrNew('ar')->sub_type_name = $request['sub_type_name']['ar'];
        $sub_type->translateOrNew('en')->sub_type_name = $request['sub_type_name']['en'];

        if ($image = $request->file('image')) {

            if ($sub_type->image) {
                $oldImage = public_path('Uploads_Images/Sub_type/' . $sub_type->image);
                if (file_exists($oldImage))
                {
                    unlink($oldImage);
                }
            }

            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Sub_type/' . $NewName));

            $sub_type->image = $NewName;
        } else {
            unset($sub_type->image);
        }

        $sub_type->save();

        Cache::forget('AllSubType');

        return redirect(route('sub_type.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(SubType $sub_type)
    {
        $sub_type_count = $sub_type->withCount('product')->findOrFail($sub_type->id);
        if ($sub_type_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        if ($sub_type->image) {
            $oldImage = public_path('Uploads_Images/Sub_type/' . $sub_type->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
        }

        $sub_type->delete();

        Cache::forget('AllSubType');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new SubTypeExport, 'SubType.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new SubTypeImport, $request->file('import'));

            Cache::forget('AllSubType');

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
