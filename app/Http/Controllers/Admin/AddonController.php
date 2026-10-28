<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AddonTemplate;
use App\Models\AuditLog;
use App\Models\TenantAddon;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erlaubt Bootsschulen, vom Superadmin vorgefertigte Addon-Banner (siehe
 * AddonTemplate) für sich zu aktivieren und mit ihren eigenen Daten zu
 * befüllen: Gutscheincode und Preis sind reine Anzeigefelder (kein
 * internes Couponsystem -- die Bootsschule verkauft das Addon selbst
 * extern in ihrem Webshop), der Kurs + die Fortschritts-Schwelle steuern,
 * wann der Banner auf der Seite dieses Kurses erscheint (siehe
 * courses/show.blade.php), der Link führt zur externen Angebotsseite.
 */
class AddonController extends Controller
{
    public function update(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'addons' => ['array'],
            'addons.*.active' => ['nullable', 'boolean'],
            'addons.*.coupon_code' => ['nullable', 'string', 'max:255'],
            'addons.*.price_label' => ['nullable', 'string', 'max:255'],
            'addons.*.discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'addons.*.bullets' => ['nullable', 'string'],
            'addons.*.trigger_course_id' => ['nullable', 'uuid', 'exists:course_definition,id'],
            'addons.*.trigger_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            // Sicherheitsreview N1: auf http/https eingeschränkt, sonst
            // ließe die bloße "url"-Regel auch javascript:/data:-Schemata durch.
            'addons.*.offer_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $rows = collect($validated['addons'] ?? []);

        // Bug-Review F2: hier stand bisher AddonTemplate::pluck('id') --
        // ALLE Vorlagen, nicht nur die aktiven. Das Formular (siehe
        // AdminDashboardController) rendert aber nur Zeilen für aktive
        // Vorlagen (AddonTemplate::where('active', true)) -- deaktiviert
        // der Superadmin eine Vorlage, fehlt ihre ID im nächsten Request
        // komplett, $active fiel hier auf false zurück, und die eigene
        // Konfiguration der Bootsschule (Gutscheincode, Preis, Bullets,
        // Trigger-Kurs, Link) wurde beim NÄCHSTEN Speichern gelöscht --
        // unabhängig davon, ob die Schule überhaupt etwas an diesem Addon
        // ändern wollte. Die Lernerseite blendet ein Addon mit deaktivierter
        // Vorlage ohnehin schon zuverlässig aus (CourseController::show(),
        // whereHas('addonTemplate', fn ($q) => $q->where('active', true))) --
        // das Löschen hier war nie nötig, um das durchzusetzen. Nur noch die
        // tatsächlich im Formular enthaltenen (= aktiven) Vorlagen werden
        // deshalb überhaupt berührt.
        // Bug-Review (Query-Parameter-Validierung): die Schlüssel des
        // addons-Arrays (Vorlagen-IDs) kamen bisher ungeprüft aus dem
        // Request direkt in where('addon_template_id', ...) bzw. ein
        // Insert gegen die uuid-Spalte tenant_addon.addon_template_id --
        // ein manipulierter, nicht-UUID-förmiger Schlüssel ließ Postgres
        // mit "invalid input syntax for type uuid" scheitern, ein
        // UUID-förmiger, aber nicht existierender Schlüssel mit einer
        // verletzten Fremdschlüssel-Zwangsbedingung. Beides endete als
        // unbehandelte QueryException mit HTTP 500, statt den Schlüssel
        // einfach zu ignorieren (analog zu VideoCourseController::
        // parseModuleIds()).
        $existingTemplateIds = AddonTemplate::whereIn('id', $rows->keys()->filter(fn ($id) => is_string($id) && Str::isUuid($id))->values())
            ->pluck('id')
            ->map(fn ($id) => (string) $id);

        $templateIds = $rows->keys()->intersect($existingTemplateIds);

        foreach ($templateIds as $templateId) {
            $row = $rows->get($templateId, []);
            $active = (bool) ($row['active'] ?? false);

            if (! $active) {
                TenantAddon::where('tenant_id', $tenant->id)->where('addon_template_id', $templateId)->delete();

                continue;
            }

            TenantAddon::updateOrCreate(
                ['tenant_id' => $tenant->id, 'addon_template_id' => $templateId],
                [
                    'active' => true,
                    'coupon_code' => trim((string) ($row['coupon_code'] ?? '')) ?: null,
                    'price_label' => trim((string) ($row['price_label'] ?? '')) ?: null,
                    'discount_percent' => isset($row['discount_percent']) && $row['discount_percent'] !== '' ? (int) $row['discount_percent'] : null,
                    'bullets' => $this->parseBullets($row['bullets'] ?? ''),
                    'trigger_course_id' => $row['trigger_course_id'] ?? null,
                    'trigger_percent' => (int) ($row['trigger_percent'] ?? 0),
                    'offer_url' => trim((string) ($row['offer_url'] ?? '')) ?: null,
                ]
            );
        }

        AuditLog::record($tenant->id, $request->user()->id, 'addons.update', 'tenant_addon', $tenant->id, null, $rows->toArray());

        return back()->with('status', 'Addons aktualisiert.');
    }

    /**
     * Textarea (eine Zeile pro Punkt) -> Array, oder null bei leerem Feld
     * (dann gelten weiter die Vorlagen-Bullets, siehe
     * TenantAddon::effectiveBullets()).
     *
     * @return string[]|null
     */
    private function parseBullets(string $raw): ?array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($line) => $line !== ''));

        return $lines ?: null;
    }
}
