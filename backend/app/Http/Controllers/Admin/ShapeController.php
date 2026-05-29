<?php

namespace App\Http\Controllers\Admin;

use App\Models\Shape;
use App\Exports\ShapeExport;
use App\Imports\ShapeImport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class ShapeController extends Controller
{
    public function index()
    {
        $shape = Shape::all();
        return view('Dashboard.shape.index' , compact('shape'));
    }

    public function create()
    {
        return view('Dashboard.shape.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'shape_name.en' => 'required|string|min:2|max:255|unique:shape_translations,shape_name',
            'shape_name.ar' => 'required|string|min:2|max:255|unique:shape_translations,shape_name',
        ]);

        $shape = new Shape;

        $shape->translateOrNew('ar')->shape_name = $request['shape_name']['ar'];
        $shape->translateOrNew('en')->shape_name = $request['shape_name']['en'];

        $shape->save();

        Cache::forget('AllShape');

        return redirect(route('shape.index'))->with('success' , trans('messages.add'));
    }

    public function edit(Shape $shape)
    {
        return view('Dashboard.shape.edit' , compact('shape'));
    }

    public function update(Request $request, Shape $shape)
    {
        $request->validate([
            'shape_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('shape_translations','shape_name')->ignore($shape->translate('en')->id)],
            'shape_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('shape_translations','shape_name')->ignore($shape->translate('ar')->id)],
        ]);

        $shape->translateOrNew('ar')->shape_name = $request['shape_name']['ar'];
        $shape->translateOrNew('en')->shape_name = $request['shape_name']['en'];

        $shape->save();

        Cache::forget('AllShape');

        return redirect(route('shape.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Shape $shape)
    {
        $shape_count = $shape->withCount('product')->findOrFail($shape->id);
        if ($shape_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $shape->delete();

        Cache::forget('AllShape');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new ShapeExport, 'shape.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new ShapeImport, $request->file('import'));

            Cache::forget('AllShape');

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
