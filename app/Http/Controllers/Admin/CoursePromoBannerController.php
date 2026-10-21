<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CoursePromoBanner;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erlaubt Bootsschulen, frei formulierte Banner anzulegen, die für einen
 * anderen oder weiterführenden Kurs werben (z. B. einen Ergänzungskurs) --
 * anders als der vorgefertigte Addon-Banner (siehe AddonController) gehört
 * der komplette Text hier der Bootsschule. Ein Banner kann gleichzeitig
 * auf mehreren Kursseiten erscheinen (course_ids); auf jeder davon gated
 * der Kursfortschritt des jeweiligen Kurses selbst gegen trigger_percent.
 * Eine Bootsschule kann mehrere unabhängige Banner anlegen, daher eine
 * echte Zeilen-CRUD statt der bulk-PATCH-Form wie bei den Addons.
 */
class CoursePromoBannerController extends Controller
{
    public function store(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();
        $validated = $this->validateBanner($request);

        CoursePromoBanner::create($validated + ['tenant_id' => $tenant->id]);

        AuditLog::record($tenant->id, $request->user()->id, 'course-promo-banners.create', 'course_promo_banner', null, null, $validated);

        return back()->with('status', 'Kursempfehlungs-Banner angelegt.');
    }

    public function update(Request $request, TenantContext $tenantContext, CoursePromoBanner $coursePromoBanner): Response
    {
        abort_unless($coursePromoBanner->tenant_id === $tenantContext->id(), 404);

        $validated = $this->validateBanner($request);
        $before = $coursePromoBanner->toArray();

        $coursePromoBanner->update($validated);

        AuditLog::record($tenantContext->id(), $request->user()->id, 'course-promo-banners.update', 'course_promo_banner', $coursePromoBanner->id, $before, $coursePromoBanner->fresh()->toArray());

        return back()->with('status', 'Kursempfehlungs-Banner aktualisiert.');
    }

    public function destroy(Request $request, TenantContext $tenantContext, CoursePromoBanner $coursePromoBanner): Response
    {
        abort_unless($coursePromoBanner->tenant_id === $tenantContext->id(), 404);

        $before = $coursePromoBanner->toArray();
        $coursePromoBanner->delete();

        AuditLog::record($tenantContext->id(), $request->user()->id, 'course-promo-banners.delete', 'course_promo_banner', $before['id'], $before, null);

        return back()->with('status', 'Kursempfehlungs-Banner gelöscht.');
    }

    /** @return array<string, mixed> */
    private function validateBanner(Request $request): array
    {
        $validated = $request->validate([
            'active' => ['nullable', 'boolean'],
            'headline' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'bullets' => ['nullable', 'string'],
            'cta_label' => ['nullable', 'string', 'max:255'],
            'price_label' => ['nullable', 'string', 'max:255'],
            'discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'coupon_code' => ['nullable', 'string', 'max:255'],
            // Sicherheitsreview N1: auf http/https eingeschränkt, sonst
            // ließe die bloße "url"-Regel auch javascript:/data:-Schemata durch.
            'offer_url' => ['nullable', 'url:http,https', 'max:2048'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['uuid', 'exists:course_definition,id'],
            'trigger_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        return [
            'active' => (bool) ($validated['active'] ?? false),
            'headline' => $validated['headline'],
            'description' => trim((string) ($validated['description'] ?? '')) ?: null,
            'bullets' => $this->parseBullets($validated['bullets'] ?? ''),
            'cta_label' => trim((string) ($validated['cta_label'] ?? '')) ?: 'Jetzt entdecken',
            'price_label' => trim((string) ($validated['price_label'] ?? '')) ?: null,
            'discount_percent' => isset($validated['discount_percent']) && $validated['discount_percent'] !== '' ? (int) $validated['discount_percent'] : null,
            'coupon_code' => trim((string) ($validated['coupon_code'] ?? '')) ?: null,
            'offer_url' => trim((string) ($validated['offer_url'] ?? '')) ?: null,
            'course_ids' => array_values($validated['course_ids'] ?? []),
            'trigger_percent' => (int) ($validated['trigger_percent'] ?? 0),
        ];
    }

    /** @return string[] */
    private function parseBullets(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($line) => $line !== ''));
    }
}
