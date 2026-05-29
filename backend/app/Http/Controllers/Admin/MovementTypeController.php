<?php

namespace App\Http\Controllers\Admin;

use App\Models\MovementType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exports\MovementTypeExport;
use App\Imports\MovementTypeImport;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Validators\ValidationException;

class MovementTypeController extends Controller
{
    public function index()
    {
        $movement_type = MovementType::all();
        return view('Dashboard.movement_type.index' , compact('movement_type'));
    }

    public function create()
    {
        return view('Dashboard.movement_type.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'movement_type_name.en' => 'required|string|min:2|max:255|unique:movement_type_translations,movement_type_name',
            'movement_type_name.ar' => 'required|string|min:2|max:255|unique:movement_type_translations,movement_type_name',
        ]);

        $movement_type = new MovementType;

        $movement_type->translateOrNew('ar')->movement_type_name = $request['movement_type_name']['ar'];
        $movement_type->translateOrNew('en')->movement_type_name = $request['movement_type_name']['en'];

        $movement_type->save();

        Cache::forget('AllMovementType');

        return redirect(route('movement_type.index'))->with('success' , trans('messages.add'));
    }

    public function edit(MovementType $movement_type)
    {
        return view('Dashboard.movement_type.edit' , compact('movement_type'));
    }

    public function update(Request $request, MovementType $movement_type)
    {
        $request->validate([
            'movement_type_name.en' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('movement_type_translations','movement_type_name')->ignore($movement_type->translate('en')->id)],
            'movement_type_name.ar' => ['required' , 'string' , 'min:2' , 'max:255' , Rule::unique('movement_type_translations','movement_type_name')->ignore($movement_type->translate('ar')->id)],
        ]);

        $movement_type->translateOrNew('ar')->movement_type_name = $request['movement_type_name']['ar'];
        $movement_type->translateOrNew('en')->movement_type_name = $request['movement_type_name']['en'];

        $movement_type->save();

        Cache::forget('AllMovementType');

        return redirect(route('movement_type.index'))->with('success' , trans('messages.edit'));
    }

    public function destroy(MovementType $movement_type)
    {
        $movement_type_count = $movement_type->withCount('product')->findOrFail($movement_type->id);
        if ($movement_type_count->product_count > 0) {
            return back()->with('error' , trans('messages.undelete'));
        }

        $movement_type->delete();

        Cache::forget('AllMovementType');

        return back()->with('success' , trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new MovementTypeExport, 'movement_type.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:5120',
            ]);

            Excel::import(new MovementTypeImport, $request->file('import'));

            Cache::forget('AllMovementType');

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
