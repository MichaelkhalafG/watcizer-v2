<?php

namespace App\Http\Controllers\Admin;

use App\Models\DisplayType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exports\DisplayTypeExport;
use App\Imports\DisplayTypeImport;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class DisplayTypeController extends Controller
{
    public function index()
    {
        $display_type = DisplayType::all();
        return view('Dashboard.display_type.index' , compact('display_type'));
    }

    public function create()
    {
        return view('Dashboard.display_type.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'display_type_name.en' => 'required|string|min:2|max:255|unique:display_type_translations,display_type_name',
            'display_type_name.ar' => 'required|string|min:2|max:255|unique:display_type_translations,display_type_name',
        ]);

        $display_type = new DisplayType;

        $display_type->translateOrNew('ar')->display_type_name = $request['display_type_name']['ar'];
        $display_type->translateOrNew('en')->display_type_name = $request['display_type_name']['en'];

        $display_type->save();

        Cache::forget('AllDisplayType');

        return redirect(route('display_type.index'))->with('success' , trans('messages.add'));
    }

    public function edit(DisplayType $display_type)
    {
        return view('Dashboard.display_type.edit' , compact('display_type'));
    }

    public function update(Request $request, DisplayType $display_type)
    {
        $request->validate([
            'display_type_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('display_type_translations','display_type_name')->ignore($display_type->translate('en')->id)],
            'display_type_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('display_type_translations','display_type_name')->ignore($display_type->translate('ar')->id)],
        ]);

        $display_type->translateOrNew('ar')->display_type_name = $request['display_type_name']['ar'];
        $display_type->translateOrNew('en')->display_type_name = $request['display_type_name']['en'];

        $display_type->save();

        Cache::forget('AllDisplayType');

        return redirect(route('display_type.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(DisplayType $display_type)
    {
        $display_type_count = $display_type->withCount('product')->findOrFail($display_type->id);
        if ($display_type_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $display_type->delete();

        Cache::forget('AllDisplayType');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new DisplayTypeExport, 'display_type.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new DisplayTypeImport, $request->file('import'));

            Cache::forget('AllDisplayType');

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
