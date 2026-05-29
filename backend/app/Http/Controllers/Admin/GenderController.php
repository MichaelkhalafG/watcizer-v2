<?php

namespace App\Http\Controllers\Admin;

use App\Models\Gender;
use Illuminate\Http\Request;
use App\Exports\GenderExport;
use App\Imports\GenderImport;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class GenderController extends Controller
{
    public function index()
    {
        $gender = Gender::all();
        return view('Dashboard.gender.index' , compact('gender'));
    }

    public function create()
    {
        return view('Dashboard.gender.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'gender_name.en' => 'required|string|min:2|max:255|unique:gender_translations,gender_name',
            'gender_name.ar' => 'required|string|min:2|max:255|unique:gender_translations,gender_name',
        ]);

        $gender = new Gender;

        $gender->translateOrNew('ar')->gender_name = $request['gender_name']['ar'];
        $gender->translateOrNew('en')->gender_name = $request['gender_name']['en'];

        $gender->save();

        Cache::forget('AllGender');

        return redirect(route('gender.index'))->with('success' , trans('messages.add'));
    }

    public function edit(Gender $gender)
    {
        return view('Dashboard.gender.edit' , compact('gender'));
    }

    public function update(Request $request, Gender $gender)
    {
        $request->validate([
            'gender_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('gender_translations','gender_name')->ignore($gender->translate('en')->id)],
            'gender_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('gender_translations','gender_name')->ignore($gender->translate('ar')->id)],
        ]);

        $gender->translateOrNew('ar')->gender_name = $request['gender_name']['ar'];
        $gender->translateOrNew('en')->gender_name = $request['gender_name']['en'];

        $gender->save();

        Cache::forget('AllGender');

        return redirect(route('gender.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(gender $gender)
    {
        $gender_count = $gender->withCount('product')->findOrFail($gender->id);
        if ($gender_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $gender->delete();

        Cache::forget('AllGender');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new GenderExport, 'gender.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new GenderImport, $request->file('import'));

            Cache::forget('AllGender');

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
