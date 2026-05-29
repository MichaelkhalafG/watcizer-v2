<?php

namespace App\Http\Controllers\Admin;

use App\Models\Material;
use Illuminate\Http\Request;
use App\Exports\MaterialExport;
use App\Imports\MaterialImport;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class MaterialController extends Controller
{
    public function index()
    {
        $material = Material::all();
        return view('Dashboard.material.index' , compact('material'));
    }

    public function create()
    {
        return view('Dashboard.material.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'material_name.en' => 'required|string|min:2|max:255|unique:material_translations,material_name',
            'material_name.ar' => 'required|string|min:2|max:255|unique:material_translations,material_name',
        ]);

        $material = new Material;

        $material->translateOrNew('ar')->material_name = $request['material_name']['ar'];
        $material->translateOrNew('en')->material_name = $request['material_name']['en'];

        $material->save();

        Cache::forget('AllMaterial');

        return redirect(route('material.index'))->with('success' , trans('messages.add'));
    }

    public function edit(Material $material)
    {
        return view('Dashboard.material.edit' , compact('material'));
    }

    public function update(Request $request, Material $material)
    {
        $request->validate([
            'material_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('material_translations','material_name')->ignore($material->translate('en')->id)],
            'material_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('material_translations','material_name')->ignore($material->translate('ar')->id)],
        ]);

        $material->translateOrNew('ar')->material_name = $request['material_name']['ar'];
        $material->translateOrNew('en')->material_name = $request['material_name']['en'];

        $material->save();

        Cache::forget('AllMaterial');

        return redirect(route('material.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Material $material)
    {
        $material_count = $material->withCount('productBandMaterial' , 'productDialCaseMaterial' , 'productDialGlassMaterial')->findOrFail($material->id);
        if ($material_count->product_band_material_count > 0 || $material_count->product_dial_case_material_count > 0 || $material_count->product_dial_glass_material_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $material->delete();

        Cache::forget('AllMaterial');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new MaterialExport, 'material.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new MaterialImport, $request->file('import'));

            Cache::forget('AllMaterial');

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
