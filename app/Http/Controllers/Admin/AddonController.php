<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AddonTemplate;
use App\Models\AuditLog;
use App\Models\TenantAddon;
use App\Support\TenantContext;
use Illuminate\Http\Request;
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
        $templateIds = AddonTemplate::pluck('id');

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
