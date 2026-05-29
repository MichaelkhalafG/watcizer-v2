<?php

namespace App\Http\Controllers\Admin;

use App\Models\SizeType;
use Illuminate\Http\Request;
use App\Exports\SizeTypeExport;
use App\Imports\SizeTypeImport;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class SizeTypeController extends Controller
{
    public function index()
    {
        $size_type = SizeType::all();
        return view('Dashboard.size_type.index' , compact('size_type'));
    }

    public function create()
    {
        return view('Dashboard.size_type.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'size_type_name.en' => 'required|string|min:1|max:255|unique:size_type_translations,size_type_name',
            'size_type_name.ar' => 'required|string|min:1|max:255|unique:size_type_translations,size_type_name',
        ]);

        $size_type = new SizeType;

        $size_type->translateOrNew('ar')->size_type_name = $request['size_type_name']['ar'];
        $size_type->translateOrNew('en')->size_type_name = $request['size_type_name']['en'];

        $size_type->save();

        Cache::forget('AllSizeType');

        return redirect(route('size_type.index'))->with('success' , trans('messages.add'));
    }

    public function edit(SizeType $size_type)
    {
        return view('Dashboard.size_type.edit' , compact('size_type'));
    }

    public function update(Request $request, SizeType $size_type)
    {
        $request->validate([
            'size_type_name.en' => ['required' , 'string' , 'min:1' , 'max:255' , Rule::unique('size_type_translations','size_type_name')->ignore($size_type->translate('en')->id)],
            'size_type_name.ar' => ['required' , 'string' , 'min:1' , 'max:255' , Rule::unique('size_type_translations','size_type_name')->ignore($size_type->translate('ar')->id)],
        ]);

        $size_type->translateOrNew('ar')->size_type_name = $request['size_type_name']['ar'];
        $size_type->translateOrNew('en')->size_type_name = $request['size_type_name']['en'];

        $size_type->save();

        Cache::forget('AllSizeType');

        return redirect(route('size_type.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(SizeType $size_type)
    {
        $size_type_count = $size_type->withCount('caseSizeTypeProduct', 'bandSizeTypeProduct', 'waterResistanceSizeTypeProduct', 'bandWidthSizeTypeProduct', 'caseThicknessSizeTypeProduct', 'watchHeightSizeTypeProduct', 'watchWidthSizeTypeProduct', 'watchLengthSizeTypeProduct')->findOrFail($size_type->id);
        if ($size_type_count->case_size_type_product_count > 0 || $size_type_count->band_size_type_product_count > 0 || $size_type_count->water_resistance_size_type_product_count > 0 || $size_type_count->band_width_size_type_product_count > 0 || $size_type_count->case_thickness_size_type_product_count > 0 || $size_type_count->watch_height_size_type_product_count > 0 || $size_type_count->watch_width_size_type_product_count > 0 || $size_type_count->watch_length_size_type_product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $size_type->delete();

        Cache::forget('AllSizeType');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new SizeTypeExport, 'size_type.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new SizeTypeImport, $request->file('import'));

            Cache::forget('AllSizeType');

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
