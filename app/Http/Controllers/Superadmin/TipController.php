<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Models\Tip;
use App\Models\TipCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TipController extends Controller
{
    public function index(): View
    {
        $categories = TipCategory::with(['tips' => function ($query) {
            $query->orderBy('sort_order')->orderBy('title');
        }])->orderBy('sort_order')->orderBy('name')->get();

        return view('superadmin.tips.index', ['categories' => $categories]);
    }

    public function create(): View
    {
        $categories = TipCategory::orderBy('sort_order')->orderBy('name')->get();

        return view('superadmin.tips.create', ['categories' => $categories]);
    }

    public function store(Request $request): Response
    {
        $validated = $this->validateTip($request);

        $validated['pdf_asset_id'] = $this->storePdfIfPresent($request);

        Tip::create($validated + ['active' => true]);

        return redirect()->route('superadmin.tips.index')->with('status', 'Tipp angelegt.');
    }

    public function edit(Tip $tip): View
    {
        $categories = TipCategory::orderBy('sort_order')->orderBy('name')->get();

        return view('superadmin.tips.edit', ['tip' => $tip, 'categories' => $categories]);
    }

    public function update(Request $request, Tip $tip): Response
    {
        $validated = $this->validateTip($request);

        if ($pdfAssetId = $this->storePdfIfPresent($request)) {
            $validated['pdf_asset_id'] = $pdfAssetId;
        }

        $tip->update($validated);

        return redirect()->route('superadmin.tips.index')->with('status', 'Tipp aktualisiert.');
    }

    public function toggleActive(Tip $tip): Response
    {
        $tip->update(['active' => ! $tip->active]);

        return back()->with('status', $tip->active ? 'Tipp aktiviert.' : 'Tipp deaktiviert.');
    }

    public function destroy(Tip $tip): Response
    {
        $tip->delete();

        return redirect()->route('superadmin.tips.index')->with('status', 'Tipp gelöscht.');
    }

    /** @return array<string, mixed> */
    private function validateTip(Request $request): array
    {
        $validated = $request->validate([
            'category_id' => ['required', 'uuid', 'exists:tip_category,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        unset($validated['pdf']);
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);

        return $validated;
    }

    private function storePdfIfPresent(Request $request): ?string
    {
        if (! $request->hasFile('pdf')) {
            return null;
        }

        $file = $request->file('pdf');
        $path = $file->storeAs('tip-attachments', Str::uuid().'.'.$file->extension(), 'public');

        $asset = MediaAsset::create([
            'asset_key' => 'tip-pdf-'.Str::random(12),
            'media_type' => 'document',
            'storage_path' => Storage::disk('public')->url($path),
            'mime_type' => $file->getMimeType(),
            'rights_status' => 'licensed',
            'status' => 'published',
        ]);

        return $asset->id;
    }
}
