<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\TipCategory;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TipCategoryController extends Controller
{
    public function index(): View
    {
        $categories = TipCategory::withCount('tips')->orderBy('sort_order')->orderBy('name')->get();

        return view('superadmin.tips.categories.index', ['categories' => $categories]);
    }

    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:tip_category,name'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        TipCategory::create([
            'name' => $validated['name'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('superadmin.tips.categories.index')->with('status', 'Kategorie angelegt.');
    }

    public function update(Request $request, TipCategory $tipCategory): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:tip_category,name,'.$tipCategory->id],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $tipCategory->update([
            'name' => $validated['name'],
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return redirect()->route('superadmin.tips.categories.index')->with('status', 'Kategorie aktualisiert.');
    }

    public function destroy(TipCategory $tipCategory): Response
    {
        if ($tipCategory->tips()->exists()) {
            return back()->withErrors(['category' => 'Diese Kategorie wird noch von Tipps verwendet und kann nicht gelöscht werden.']);
        }

        $tipCategory->delete();

        return redirect()->route('superadmin.tips.categories.index')->with('status', 'Kategorie gelöscht.');
    }
}
