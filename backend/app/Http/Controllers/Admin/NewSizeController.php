<?php
namespace App\Http\Controllers\Admin;

use App\Models\NewSize;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class NewSizeController extends Controller
{
    public function index()
    {
        $sizes = NewSize::orderBy('type')->orderBy('name_en')->get()->groupBy('type');
        return view('Dashboard.sizes.index', compact('sizes'));
    }

    public function create()
    {
        $types = NewSize::TYPES;
        return view('Dashboard.sizes.create', compact('types'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name_en' => 'required|string|max:100',
            'name_ar' => 'required|string|max:100',
            'type'    => 'required|in:clothing,shoes,watch,general',
        ]);

        NewSize::create($request->only('name_en', 'name_ar', 'type', 'is_active'));

        return redirect()->route('new-sizes.index')->with('success', 'Size added successfully');
    }

    public function edit(NewSize $size)
    {
        $types = NewSize::TYPES;
        return view('Dashboard.sizes.edit', compact('size', 'types'));
    }

    public function update(Request $request, NewSize $size)
    {
        $request->validate([
            'name_en' => 'required|string|max:100',
            'name_ar' => 'required|string|max:100',
            'type'    => 'required|in:clothing,shoes,watch,general',
        ]);

        $size->update($request->only('name_en', 'name_ar', 'type', 'is_active'));

        return redirect()->route('new-sizes.index')->with('success', 'Size updated successfully');
    }

    public function destroy(NewSize $size)
    {
        $size->delete();
        return back()->with('success', 'Size deleted');
    }

    public function show(NewSize $size) {}
}