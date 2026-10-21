<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Models\TenantCourseDisabled;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erlaubt einer Bootsschule, aus dem sitewide freigegebenen Kurskatalog
 * (course_definition.site_enabled = true) auszuwählen, welche Kurse sie
 * selbst anbietet. Ein hier abgewählter Kurs verschwindet für ihre
 * Lernenden vollständig aus der Kursübersicht -- auch wenn bereits ein
 * Entitlement dafür besteht (siehe EntitlementService::activeEntitlements()).
 * Sitewide deaktivierte Kurse tauchen hier gar nicht erst auf: eine
 * Bootsschule kann einen vom Superadmin gesperrten Kurs nicht aktivieren.
 *
 * Auf derselben Seite (ein Formular, ein "Speichern") hinterlegt die
 * Bootsschule optional je Kurs auch gleich ihren eigenen Webshop-Kauf-Link
 * (vormals eigener Tab, siehe WebshopLinkController -- jetzt hier
 * zusammengeführt, weil beides dieselbe Kursliste betrifft). Ein Link
 * bleibt auch für einen gerade abgewählten Kurs gespeichert, damit die
 * Bootsschule ihn schon für später hinterlegen kann.
 *
 * Zusätzlich zwei Einstellungen für die ganze Bootsschule (nicht je Kurs):
 * wie viele Tage ein neu vergebenes Entitlement gültig bleibt
 * (course_validity_days, siehe CouponRedemptionService), und der
 * Webshop-Link, über den Teilnehmer nach Ablauf einen Verlängerungs-Code
 * kaufen können (renewal_webshop_link, siehe courses/index.blade.php).
 */
class CourseSelectionController extends Controller
{
    public function update(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'enabled' => ['array'],
            'enabled.*' => ['uuid'],
            'links' => ['array'],
            'links.*' => ['nullable', 'url', 'max:2048'],
            'course_validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'renewal_webshop_link' => ['nullable', 'url', 'max:2048'],
        ]);

        $tenant->branding()->update([
            'course_validity_days' => $validated['course_validity_days'] ?? $tenant->branding->course_validity_days,
            'renewal_webshop_link' => $validated['renewal_webshop_link'] ?? null,
        ]);

        $enabledIds = collect($validated['enabled'] ?? []);
        $links = collect($validated['links'] ?? []);
        $courseIds = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)->pluck('id');

        foreach ($courseIds as $courseId) {
            if ($enabledIds->contains($courseId)) {
                TenantCourseDisabled::where('tenant_id', $tenant->id)->where('course_id', $courseId)->delete();
            } else {
                TenantCourseDisabled::firstOrCreate(['tenant_id' => $tenant->id, 'course_id' => $courseId]);
            }

            $url = trim((string) $links->get($courseId, ''));

            if ($url === '') {
                CourseWebshopLink::where('tenant_id', $tenant->id)->where('course_id', $courseId)->delete();

                continue;
            }

            CourseWebshopLink::updateOrCreate(
                ['tenant_id' => $tenant->id, 'course_id' => $courseId],
                ['url' => $url]
            );
        }

        AuditLog::record($tenant->id, $request->user()->id, 'course-selection.update', 'tenant_course_disabled', $tenant->id, null, ['enabled' => $enabledIds->values()->toArray()]);
        AuditLog::record($tenant->id, $request->user()->id, 'webshop-links.update', 'course_webshop_link', $tenant->id, null, $links->toArray());

        return back()->with('status', 'Kursauswahl aktualisiert.');
    }
}
