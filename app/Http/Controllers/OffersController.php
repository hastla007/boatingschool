<?php

namespace App\Http\Controllers;

use App\Models\CoursePromoBanner;
use App\Models\TenantAddon;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sammelseite für alle Addon- und Kursempfehlungs-Banner, für die der
 * Lerner gerade in Frage kommt (gleiche Freischalt-Logik wie die Banner
 * auf den Kursseiten, siehe CourseController::show()) -- unabhängig
 * davon, ob er einen Banner per X weggeklickt hat: das Dismiss steckt nur
 * in localStorage auf der jeweiligen Kursseite, hier bleibt das Angebot
 * weiter auffindbar.
 */
class OffersController extends Controller
{
    public function index(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseProgressService $progressService): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $courses = $entitlements->activeCourses($tenant, $user);
        $percentByCourseId = $courses->mapWithKeys(fn ($course) => [
            $course->id => $progressService->overallPercent($course, $tenant, $user),
        ]);
        $courseIds = $courses->pluck('id');

        $addons = TenantAddon::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->whereIn('trigger_course_id', $courseIds)
            ->whereHas('addonTemplate', fn ($q) => $q->where('active', true))
            ->with('addonTemplate')
            ->get()
            ->filter(fn ($addon) => ($percentByCourseId[$addon->trigger_course_id] ?? 0) >= $addon->trigger_percent)
            ->sortByDesc('trigger_percent')
            ->values();

        $promoBanners = CoursePromoBanner::where('tenant_id', $tenant->id)
            ->where('active', true)
            ->get()
            ->filter(function ($banner) use ($percentByCourseId) {
                foreach ($banner->course_ids ?? [] as $courseId) {
                    if (($percentByCourseId[$courseId] ?? null) !== null && $percentByCourseId[$courseId] >= $banner->trigger_percent) {
                        return true;
                    }
                }

                return false;
            })
            ->sortByDesc('trigger_percent')
            ->values();

        return view('offers.index', [
            'tenant' => $tenant,
            'addons' => $addons,
            'promoBanners' => $promoBanners,
        ]);
    }
}
