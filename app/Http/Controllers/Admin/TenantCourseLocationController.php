<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TenantCourseLocation;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Praxis-/Prüfungsort-Paare einer Bootsschule (siehe TenantCourseLocation):
 * der Kunde wählt im Praxisformular (Feldtyp "location", siehe
 * PraxisFormTemplate) immer den Praxisort, der zugehörige Prüfungsort ist
 * fix daran gekoppelt. Eine Bootsschule kann mehrere Paare pro Kurs
 * anlegen (z. B. mehrere Standorte) und ein Paar mehreren Kursen
 * gleichzeitig zuweisen -- echte Zeilen-CRUD wie bei den
 * Kursempfehlungs-Bannern, nicht die Bulk-PATCH-Form wie bei den Addons.
 */
class TenantCourseLocationController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();
        $validated = $this->validateLocation($request);

        TenantCourseLocation::create($validated + ['tenant_id' => $tenant->id]);

        AuditLog::record($tenant->id, $request->user()->id, 'tenant-course-locations.create', 'tenant_course_location', null, null, $validated);

        return back()->with('status', 'Ort angelegt.');
    }

    public function update(Request $request, TenantContext $tenantContext, TenantCourseLocation $tenantCourseLocation): Response
    {
        abort_unless($tenantCourseLocation->tenant_id === $tenantContext->id(), 404);

        $validated = $this->validateLocation($request);
        $before = $tenantCourseLocation->toArray();

        $tenantCourseLocation->update($validated);

        AuditLog::record($tenantContext->id(), $request->user()->id, 'tenant-course-locations.update', 'tenant_course_location', $tenantCourseLocation->id, $before, $tenantCourseLocation->fresh()->toArray());

        return back()->with('status', 'Ort aktualisiert.');
    }

    public function destroy(Request $request, TenantContext $tenantContext, TenantCourseLocation $tenantCourseLocation): Response
    {
        abort_unless($tenantCourseLocation->tenant_id === $tenantContext->id(), 404);

        $before = $tenantCourseLocation->toArray();
        $tenantCourseLocation->delete();

        AuditLog::record($tenantContext->id(), $request->user()->id, 'tenant-course-locations.delete', 'tenant_course_location', $before['id'], $before, null);

        return back()->with('status', 'Ort gelöscht.');
    }

    /** @return array<string, mixed> */
    private function validateLocation(Request $request): array
    {
        $validated = $request->validate([
            'praxis_ort' => ['required', 'string', 'max:255'],
            'praxis_strasse' => ['nullable', 'string', 'max:255'],
            'praxis_plz' => ['nullable', 'string', 'max:20'],
            'praxis_stadt' => ['nullable', 'string', 'max:255'],
            'praxis_land' => ['nullable', 'string', 'max:255'],
            'pruefung_ort' => ['nullable', 'string', 'max:255'],
            'pruefung_strasse' => ['nullable', 'string', 'max:255'],
            'pruefung_plz' => ['nullable', 'string', 'max:20'],
            'pruefung_stadt' => ['nullable', 'string', 'max:255'],
            'pruefung_land' => ['nullable', 'string', 'max:255'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['uuid', 'exists:course_definition,id'],
        ]);

        // Ist der Prüfungsort (inkl. Anschrift) identisch mit dem Praxisort,
        // deaktiviert das Frontend ("Gleich wie Praxisort") die Prüfungsort-
        // Felder, wodurch sie leer ankommen -- hier fällt jedes leere
        // Prüfungsort-Feld einzeln auf den entsprechenden Praxisort-Wert zurück.
        $fallback = fn (string $pruefungKey, string $praxisKey) => trim((string) ($validated[$pruefungKey] ?? '')) ?: ($validated[$praxisKey] ?? null);

        return [
            'praxis_ort' => $validated['praxis_ort'],
            'praxis_strasse' => $validated['praxis_strasse'] ?? null,
            'praxis_plz' => $validated['praxis_plz'] ?? null,
            'praxis_stadt' => $validated['praxis_stadt'] ?? null,
            'praxis_land' => $validated['praxis_land'] ?? null,
            'pruefung_ort' => $fallback('pruefung_ort', 'praxis_ort'),
            'pruefung_strasse' => $fallback('pruefung_strasse', 'praxis_strasse'),
            'pruefung_plz' => $fallback('pruefung_plz', 'praxis_plz'),
            'pruefung_stadt' => $fallback('pruefung_stadt', 'praxis_stadt'),
            'pruefung_land' => $fallback('pruefung_land', 'praxis_land'),
            'course_ids' => array_values($validated['course_ids'] ?? []),
        ];
    }
}
