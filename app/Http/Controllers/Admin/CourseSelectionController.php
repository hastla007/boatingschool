<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\TenantCourseDisabled;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erlaubt einer Bootsschule, aus dem sitewide freigegebenen Kurskatalog
 * (course_definition.site_enabled = true) auszuwählen, welche Kurse sie
 * selbst anbietet. Ein hier abgewählter Kurs verschwindet für ihre
 * Lernenden vollständig aus der Kursübersicht -- auch wenn bereits ein
 * Entitlement dafür besteht (siehe EntitlementService::activeEntitlements()).
 * Sitewide deaktivierte Kurse tauchen hier gar nicht erst auf: eine
 * Bootsschule kann einen vom Superadmin gesperrten Kurs nicht aktivieren.
 */
class CourseSelectionController extends Controller
{
    public function edit(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();

        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)
            ->orderBy('name')->get();

        $disabledCourseIds = TenantCourseDisabled::where('tenant_id', $tenant->id)->pluck('course_id');

        return view('admin.course-selection', ['courses' => $courses, 'disabledCourseIds' => $disabledCourseIds]);
    }

    public function update(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'enabled' => ['array'],
            'enabled.*' => ['uuid'],
        ]);

        $enabledIds = collect($validated['enabled'] ?? []);
        $courseIds = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')
            ->where('site_enabled', true)->pluck('id');

        foreach ($courseIds as $courseId) {
            if ($enabledIds->contains($courseId)) {
                TenantCourseDisabled::where('tenant_id', $tenant->id)->where('course_id', $courseId)->delete();
            } else {
                TenantCourseDisabled::firstOrCreate(['tenant_id' => $tenant->id, 'course_id' => $courseId]);
            }
        }

        AuditLog::record($tenant->id, $request->user()->id, 'course-selection.update', 'tenant_course_disabled', $tenant->id, null, ['enabled' => $enabledIds->values()->toArray()]);

        return back()->with('status', 'Kursauswahl aktualisiert.');
    }
}
