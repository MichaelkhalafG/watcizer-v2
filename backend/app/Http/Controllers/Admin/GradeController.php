<?php

namespace App\Http\Controllers\Admin;

use App\Models\Grade;
use App\Exports\GradeExport;
use App\Imports\GradeImport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Intervention\Image\Drivers\Gd\Driver;
use Maatwebsite\Excel\Validators\ValidationException;

class GradeController extends Controller
{
    public function index()
    {
        $grade = Grade::all();
        return view('Dashboard.grade.index' , compact('grade'));
    }

    public function create()
    {
        return view('Dashboard.grade.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'grade_name.en'  => 'required|string|min:2|max:255|unique:grade_translations,grade_name',
            'grade_name.ar'  => 'required|string|min:2|max:255|unique:grade_translations,grade_name',
            'description.en' => 'required|string',
            'description.ar' => 'required|string',
            'image'          => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',

        ]);

        $grade = new Grade;

        $grade->translateOrNew('ar')->grade_name  = $request['grade_name']['ar'];
        $grade->translateOrNew('en')->grade_name  = $request['grade_name']['en'];
        $grade->translateOrNew('ar')->description = $request['description']['ar'];
        $grade->translateOrNew('en')->description = $request['description']['en'];

        if ($image = $request->file('image')) {
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Grade/' . $NewName));

            $grade->image = $NewName;
        } else {
            unset($grade->image);
        }

        $grade->save();

        Cache::forget('AllGrade');

        return redirect(route('grade.index'))->with('success' , trans('messages.add'));
    }

    public function edit(Grade $grade)
    {
        return view('Dashboard.grade.edit' , compact('grade'));
    }

    public function update(Request $request, Grade $grade)
    {
        $request->validate([
            'grade_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('grade_translations','grade_name')->ignore($grade->translate('en')->id)],
            'grade_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('grade_translations','grade_name')->ignore($grade->translate('ar')->id)],
            'description.en' => 'required|string',
            'description.ar' => 'required|string',
            'image'          => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',

        ]);

        $grade->translateOrNew('ar')->grade_name  = $request['grade_name']['ar'];
        $grade->translateOrNew('en')->grade_name  = $request['grade_name']['en'];
        $grade->translateOrNew('ar')->description = $request['description']['ar'];
        $grade->translateOrNew('en')->description = $request['description']['en'];

        if ($image = $request->file('image')) {

            if ($grade->image) {
                $oldImage = public_path('Uploads_Images/Grade/' . $grade->image);
                if (file_exists($oldImage))
                {
                    unlink($oldImage);
                }
            }

            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Grade/' . $NewName));

            $grade->image = $NewName;
        } else {
            unset($grade->image);
        }

        $grade->save();

        Cache::forget('AllGrade');

        return redirect(route('grade.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Grade $grade)
    {
        $grade_count = $grade->withCount('product')->findOrFail($grade->id);
        if ($grade_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        if ($grade->image) {
            $oldImage = public_path('Uploads_Images/Grade/' . $grade->image);
            if (file_exists($oldImage))
            {
                unlink($oldImage);
            }
        }

        $grade->delete();

        Cache::forget('AllGrade');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new GradeExport, 'Grade.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new GradeImport, $request->file('import'));

            Cache::forget('AllGrade');

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
