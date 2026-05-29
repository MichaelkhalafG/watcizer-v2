<?php

namespace App\Http\Controllers\Admin;

use App\Models\Color;
use App\Exports\ColorExport;
use App\Imports\ColorImport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class ColorController extends Controller
{
    public function index()
    {
        $color = Color::all();
        return view('Dashboard.color.index' , compact('color'));
    }

    public function create()
    {
        return view('Dashboard.color.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'color_value'   => 'required|string|min:2|max:255|hex_color|unique:colors,color_value',
            'color_name.en' => 'nullable|string|min:2|max:255',
            'color_name.ar' => 'nullable|string|min:2|max:255',
        ]);

        $color = new Color;

        $color->color_value                              = $request->color_value;
        $color->translateOrNew('ar')->color_name = $request['color_name']['ar'];
        $color->translateOrNew('en')->color_name = $request['color_name']['en'];

        $color->save();

        Cache::forget('AllColor');

        return redirect(route('color.index'))->with('success' , trans('messages.add'));
    }

    public function edit(Color $color)
    {
        return view('Dashboard.color.edit' , compact('color'));
    }

    public function update(Request $request, Color $color)
    {
        $request->validate([
            'color_value' => ['required' , 'string' , 'min:2' , 'max:255' , 'hex_color' , Rule::unique('colors','color_value')->ignore($color->id)],
            'color_name.en' => ['nullable' , 'string' , 'min:2' , 'max:255'],
            'color_name.ar' => ['nullable' , 'string' , 'min:2' , 'max:255'],
        ]);

        $color->color_value                              = $request->color_value;
        $color->translateOrNew('ar')->color_name = $request['color_name']['ar'];
        $color->translateOrNew('en')->color_name = $request['color_name']['en'];

        $color->save();

        Cache::forget('AllColor');

        return redirect(route('color.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(Color $color)
    {
        $color_count = $color->withCount('productDialColor' , 'productBandColor')->findOrFail($color->id);
        if ($color_count->product_dial_color_count > 0 || $color_count->product_band_color_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $color->delete();

        Cache::forget('AllColor');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new ColorExport, 'Color.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new ColorImport, $request->file('import'));

            Cache::forget('AllColor');

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
