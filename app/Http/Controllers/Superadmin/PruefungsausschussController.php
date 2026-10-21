<?php

namespace App\Http\Controllers\Superadmin;

use App\Http\Controllers\Controller;
use App\Models\CourseDefinition;
use App\Models\Pruefungsausschuss;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verwaltung der Prüfungsausschuss-Adressen (siehe Pruefungsausschuss,
 * ursprünglich aus der DMYV-Liste importiert, siehe
 * PruefungsausschussSeeder) -- plattformweit, keine Bootsschule kann sie
 * selbst anlegen oder ändern, nur einem Praxisort zuordnen.
 */
class PruefungsausschussController extends Controller
{
    public function index(): View
    {
        $pruefungsausschuesse = Pruefungsausschuss::orderBy('sort_order')->orderBy('kommission')->get();
        $courses = $this->courses()->keyBy('id');

        return view('superadmin.pruefungsausschuesse.index', ['pruefungsausschuesse' => $pruefungsausschuesse, 'courses' => $courses]);
    }

    public function create(): View
    {
        return view('superadmin.pruefungsausschuesse.create', ['courses' => $this->courses()]);
    }

    public function store(Request $request): Response
    {
        $validated = $this->validatePruefungsausschuss($request);

        Pruefungsausschuss::create($validated);

        return redirect()->route('superadmin.pruefungsausschuesse.index')->with('status', 'Prüfungsausschuss angelegt.');
    }

    public function edit(Pruefungsausschuss $pruefungsausschuss): View
    {
        return view('superadmin.pruefungsausschuesse.edit', [
            'pruefungsausschuss' => $pruefungsausschuss,
            'courses' => $this->courses(),
        ]);
    }

    public function update(Request $request, Pruefungsausschuss $pruefungsausschuss): Response
    {
        $validated = $this->validatePruefungsausschuss($request);

        $pruefungsausschuss->update($validated);

        return redirect()->route('superadmin.pruefungsausschuesse.index')->with('status', 'Prüfungsausschuss aktualisiert.');
    }

    public function destroy(Pruefungsausschuss $pruefungsausschuss): Response
    {
        // Praxisorte, die diesen Ausschuss gewählt haben, verlieren die
        // Zuordnung (pruefungsausschuss_id nullOnDelete, siehe Migration)
        // statt dass das Löschen hier blockiert.
        $pruefungsausschuss->delete();

        return redirect()->route('superadmin.pruefungsausschuesse.index')->with('status', 'Prüfungsausschuss gelöscht.');
    }

    /** @return array<string, mixed> */
    private function validatePruefungsausschuss(Request $request): array
    {
        $validated = $request->validate([
            'kommission' => ['required', 'string', 'max:255'],
            'standorttyp' => ['nullable', 'string', 'max:255'],
            'kontaktperson' => ['nullable', 'string', 'max:255'],
            'strasse' => ['nullable', 'string', 'max:255'],
            'plz' => ['nullable', 'string', 'max:20'],
            'stadt' => ['nullable', 'string', 'max:255'],
            'telefon' => ['nullable', 'string', 'max:255'],
            'fax' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'sprechzeiten' => ['nullable', 'string', 'max:255'],
            'hinweis' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['uuid', 'exists:course_definition,id'],
        ]);

        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 0);
        $validated['course_ids'] = array_values($validated['course_ids'] ?? []);

        return $validated;
    }

    /** Globaler Kurskatalog (plattformweit, keine Tenant-Kurse) zur Auswahl je Prüfungsausschuss. */
    private function courses()
    {
        return CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->orderBy('name')->get();
    }
}
