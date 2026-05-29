<?php
namespace App\Http\Controllers\Admin;

use App\Models\NewColor;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class NewColorController extends Controller
{
    public function index()
    {
        $colors = NewColor::orderBy('name_en')->get();
        return view('Dashboard.colors.index', compact('colors'));
    }

    public function create()
    {
        return view('Dashboard.colors.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name_en' => 'required|string|max:100',
            'name_ar' => 'required|string|max:100',
            'hex'     => 'required|string|max:7',
        ]);

        $hex = $request->hex;
        if (!str_starts_with($hex, '#')) {
            $hex = '#' . $hex;
        }

        NewColor::create([
            'name_en'   => $request->name_en,
            'name_ar'   => $request->name_ar,
            'hex'       => $hex,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('new-colors.index')->with('success', 'Color added successfully');
    }

    public function edit(NewColor $color)
    {
        return view('Dashboard.colors.edit', compact('color'));
    }

    public function update(Request $request, NewColor $color)
    {
        $request->validate([
            'name_en' => 'required|string|max:100',
            'name_ar' => 'required|string|max:100',
            'hex'     => 'required|string|max:7',
        ]);

        $hex = $request->hex;
        if (!str_starts_with($hex, '#')) {
            $hex = '#' . $hex;
        }

        $color->update([
            'name_en'   => $request->name_en,
            'name_ar'   => $request->name_ar,
            'hex'       => $hex,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return redirect()->route('new-colors.index')->with('success', 'Color updated successfully');
    }

    public function destroy(NewColor $color)
    {
        $color->delete();
        return back()->with('success', 'Color deleted');
    }

    public function show(NewColor $color) {}
}