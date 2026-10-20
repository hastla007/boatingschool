<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\AddonTemplate;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AddonTemplateController extends Controller
{
    public function index(): View
    {
        $templates = AddonTemplate::orderBy('sort_order')->orderBy('title')->get();

        return view('superadmin.addon-templates.index', ['templates' => $templates]);
    }

    public function create(): View
    {
        return view('superadmin.addon-templates.create');
    }

    public function store(Request $request): Response
    {
        $validated = $this->validateTemplate($request);

        AddonTemplate::create($validated + ['active' => true]);

        return redirect()->route('superadmin.addon-templates.index')->with('status', 'Addon-Vorlage angelegt.');
    }

    public function edit(AddonTemplate $addonTemplate): View
    {
        return view('superadmin.addon-templates.edit', ['template' => $addonTemplate]);
    }

    public function update(Request $request, AddonTemplate $addonTemplate): Response
    {
        $validated = $this->validateTemplate($request);

        $addonTemplate->update($validated);

        return redirect()->route('superadmin.addon-templates.index')->with('status', 'Addon-Vorlage aktualisiert.');
    }

    public function toggleActive(AddonTemplate $addonTemplate): Response
    {
        $addonTemplate->update(['active' => ! $addonTemplate->active]);

        return back()->with('status', $addonTemplate->active ? 'Addon-Vorlage aktiviert.' : 'Addon-Vorlage deaktiviert.');
    }

    public function destroy(AddonTemplate $addonTemplate): Response
    {
        $addonTemplate->delete();

        return redirect()->route('superadmin.addon-templates.index')->with('status', 'Addon-Vorlage gelöscht.');
    }

    /** @return array<string, mixed> */
    private function validateTemplate(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'badge_text' => ['nullable', 'string', 'max:255'],
            'stat_text' => ['nullable', 'string', 'max:255'],
            'headline' => ['required', 'string', 'max:255'],
            'bullets' => ['nullable', 'string'],
            'cta_label' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $validated['bullets'] = array_values(array_filter(array_map(
            'trim',
            explode("\n", $validated['bullets'] ?? '')
        ), fn ($line) => $line !== ''));

        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);

        return $validated;
    }
}
