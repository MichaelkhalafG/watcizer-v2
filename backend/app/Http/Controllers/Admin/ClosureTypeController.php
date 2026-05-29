<?php

namespace App\Http\Controllers\Admin;

use App\Models\ClosureType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exports\ClosureTypeExport;
use App\Imports\ClosureTypeImport;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class ClosureTypeController extends Controller
{
    public function index()
    {
        $closure_type = ClosureType::all();
        return view('Dashboard.closure_type.index' , compact('closure_type'));
    }

    public function create()
    {
        return view('Dashboard.closure_type.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'closure_type_name.en' => 'required|string|min:2|max:255|unique:closure_type_translations,closure_type_name',
            'closure_type_name.ar' => 'required|string|min:2|max:255|unique:closure_type_translations,closure_type_name',
        ]);

        $closure_type = new ClosureType;

        $closure_type->translateOrNew('ar')->closure_type_name = $request['closure_type_name']['ar'];
        $closure_type->translateOrNew('en')->closure_type_name = $request['closure_type_name']['en'];

        $closure_type->save();

        Cache::forget('AllClosureType');

        return redirect(route('closure_type.index'))->with('success' , trans('messages.add'));
    }

    public function edit(ClosureType $closure_type)
    {
        return view('Dashboard.closure_type.edit' , compact('closure_type'));
    }

    public function update(Request $request, ClosureType $closure_type)
    {
        $request->validate([
            'closure_type_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('closure_type_translations','closure_type_name')->ignore($closure_type->translate('en')->id)],
            'closure_type_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('closure_type_translations','closure_type_name')->ignore($closure_type->translate('ar')->id)],
        ]);

        $closure_type->translateOrNew('ar')->closure_type_name = $request['closure_type_name']['ar'];
        $closure_type->translateOrNew('en')->closure_type_name = $request['closure_type_name']['en'];

        $closure_type->save();

        Cache::forget('AllClosureType');

        return redirect(route('closure_type.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(ClosureType $closure_type)
    {
        $closure_type_count = $closure_type->withCount('product')->findOrFail($closure_type->id);
        if ($closure_type_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $closure_type->delete();

        Cache::forget('AllClosureType');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new ClosureTypeExport, 'closure_type.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new ClosureTypeImport, $request->file('import'));

            Cache::forget('AllClosureType');

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
