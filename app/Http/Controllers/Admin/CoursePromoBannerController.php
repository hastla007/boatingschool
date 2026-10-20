<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\CoursePromoBanner;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erlaubt Bootsschulen, auf der Seite eines Kurses für einen anderen oder
 * weiterführenden Kurs (z. B. einen Ergänzungskurs) zu werben -- anders
 * als der vorgefertigte Addon-Banner (siehe AddonController) gehört
 * Überschrift und Beschreibungstext hier komplett der Bootsschule, Preis/
 * Rabatt/Gutscheincode und der Link zur externen Angebotsseite sind
 * optional.
 */
class CoursePromoBannerController extends Controller
{
    public function update(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'banners' => ['array'],
            'banners.*.active' => ['nullable', 'boolean'],
            'banners.*.headline' => ['nullable', 'string', 'max:255'],
            'banners.*.description' => ['nullable', 'string', 'max:2000'],
            'banners.*.bullets' => ['nullable', 'string'],
            'banners.*.cta_label' => ['nullable', 'string', 'max:255'],
            'banners.*.price_label' => ['nullable', 'string', 'max:255'],
            'banners.*.discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'banners.*.coupon_code' => ['nullable', 'string', 'max:255'],
            'banners.*.offer_url' => ['nullable', 'url', 'max:2048'],
            'banners.*.trigger_course_id' => ['nullable', 'uuid', 'exists:course_definition,id'],
            'banners.*.trigger_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $rows = collect($validated['banners'] ?? []);
        $courseIds = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->pluck('id');

        foreach ($courseIds as $courseId) {
            $row = $rows->get($courseId, []);
            $active = (bool) ($row['active'] ?? false);

            if (! $active) {
                CoursePromoBanner::where('tenant_id', $tenant->id)->where('course_id', $courseId)->delete();

                continue;
            }

            CoursePromoBanner::updateOrCreate(
                ['tenant_id' => $tenant->id, 'course_id' => $courseId],
                [
                    'active' => true,
                    'headline' => trim((string) ($row['headline'] ?? '')) ?: null,
                    'description' => trim((string) ($row['description'] ?? '')) ?: null,
                    'bullets' => $this->parseBullets($row['bullets'] ?? ''),
                    'cta_label' => trim((string) ($row['cta_label'] ?? '')) ?: 'Jetzt entdecken',
                    'price_label' => trim((string) ($row['price_label'] ?? '')) ?: null,
                    'discount_percent' => isset($row['discount_percent']) && $row['discount_percent'] !== '' ? (int) $row['discount_percent'] : null,
                    'coupon_code' => trim((string) ($row['coupon_code'] ?? '')) ?: null,
                    'offer_url' => trim((string) ($row['offer_url'] ?? '')) ?: null,
                    'trigger_course_id' => $row['trigger_course_id'] ?? null,
                    'trigger_percent' => (int) ($row['trigger_percent'] ?? 0),
                ]
            );
        }

        AuditLog::record($tenant->id, $request->user()->id, 'course-promo-banners.update', 'course_promo_banner', $tenant->id, null, $rows->toArray());

        return back()->with('status', 'Kursempfehlungen aktualisiert.');
    }

    /**
     * Textarea (eine Zeile pro Punkt) -> Array.
     *
     * @return string[]
     */
    private function parseBullets(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($line) => $line !== ''));
    }
}
