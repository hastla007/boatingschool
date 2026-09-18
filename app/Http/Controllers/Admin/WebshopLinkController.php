<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erlaubt Bootsschulen mit eigenem Webshop, pro Kurs einen Kauf-Link zu
 * hinterlegen. Ist ein Link gesetzt, ersetzt er auf der Kursübersicht den
 * Standardhinweis "Kein Zugang -- bitte bei deiner Bootsschule anfragen"
 * durch einen "Jetzt kaufen"-Button (siehe CourseController::index()).
 */
class WebshopLinkController extends Controller
{
    public function edit(TenantContext $tenantContext): View
    {
        $tenant = $tenantContext->tenant();

        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->orderBy('name')->get();
        $links = CourseWebshopLink::where('tenant_id', $tenant->id)->pluck('url', 'course_id');

        return view('admin.webshop-links', ['courses' => $courses, 'links' => $links]);
    }

    public function update(Request $request, TenantContext $tenantContext): Response
    {
        $tenant = $tenantContext->tenant();

        $validated = $request->validate([
            'links' => ['array'],
            'links.*' => ['nullable', 'url', 'max:2048'],
        ]);

        $urls = collect($validated['links'] ?? []);
        $courseIds = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->pluck('id');

        foreach ($courseIds as $courseId) {
            $url = trim($urls->get($courseId, ''));

            if ($url === '') {
                CourseWebshopLink::where('tenant_id', $tenant->id)->where('course_id', $courseId)->delete();

                continue;
            }

            CourseWebshopLink::updateOrCreate(
                ['tenant_id' => $tenant->id, 'course_id' => $courseId],
                ['url' => $url]
            );
        }

        AuditLog::record($tenant->id, $request->user()->id, 'webshop-links.update', 'course_webshop_link', $tenant->id, null, $urls->toArray());

        return back()->with('status', 'Webshop-Links aktualisiert.');
    }
}
