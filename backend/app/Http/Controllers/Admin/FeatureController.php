<?php

namespace App\Http\Controllers\Admin;

use App\Models\Feature;
use Illuminate\Http\Request;
use App\Exports\FeatureExport;
use App\Imports\FeatureImport;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class FeatureController extends Controller
{
    public function index()
    {
        $feature = Feature::all();
        return view('Dashboard.feature.index' , compact('feature'));
    }

    public function create()
    {
        return view('Dashboard.feature.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'feature_name.en' => 'required|string|min:2|max:255|unique:feature_translations,feature_name',
            'feature_name.ar' => 'required|string|min:2|max:255|unique:feature_translations,feature_name',
        ]);

        $feature = new Feature;

        $feature->translateOrNew('ar')->feature_name = $request['feature_name']['ar'];
        $feature->translateOrNew('en')->feature_name = $request['feature_name']['en'];

        $feature->save();

        Cache::forget('AllFeature');

        return redirect(route('feature.index'))->with('success' , trans('messages.add'));
    }

    public function edit(Feature $feature)
    {
        return view('Dashboard.feature.edit' , compact('feature'));
    }

    public function update(Request $request, Feature $feature)
    {
        $request->validate([
            'feature_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('feature_translations','feature_name')->ignore($feature->translate('en')->id)],
            'feature_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('feature_translations','feature_name')->ignore($feature->translate('ar')->id)],
        ]);

        $feature->translateOrNew('ar')->feature_name = $request['feature_name']['ar'];
        $feature->translateOrNew('en')->feature_name = $request['feature_name']['en'];

        $feature->save();

        Cache::forget('AllFeature');

        return redirect(route('feature.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Feature $feature)
    {
        $feature_count = $feature->withCount('product')->findOrFail($feature->id);
        if ($feature_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $feature->delete();

        Cache::forget('AllFeature');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new FeatureExport, 'feature.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new FeatureImport, $request->file('import'));

            Cache::forget('AllFeature');

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
