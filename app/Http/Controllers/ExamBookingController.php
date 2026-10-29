<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Services\EntitlementService;
use App\Support\ExamBooking;
use App\Support\ExamRegistration;
use App\Support\FunkTraining;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Seite "Prüfung buchen": Anmeldeweg, Gebühren und Ablauf der amtlichen
 * Prüfung, nur für Kurse, bei denen die Bootsschule die Selbstanmeldung
 * gewählt hat (ExamRegistration::SELF); sonst gilt das Termintool.
 */
class ExamBookingController extends Controller
{
    public function show(Request $request, TenantContext $tenantContext, EntitlementService $entitlements, CourseDefinition $course): View
    {
        abort_unless($entitlements->hasAccess($tenantContext->tenant(), $request->user(), $course), 403);
        abort_unless(ExamRegistration::isSelf($tenantContext->tenant(), $course), 404);

        $pages = ExamBooking::pagesFor($course);
        $selected = ExamBooking::selectedPage($course, $request->query('teil'));
        $page = ExamBooking::page($selected);
        $funkCatalogs = FunkTraining::catalogsFor($course);

        $links = [
            'exam' => route('exam.intro', $course),
            'navigation' => $course->navigationTasks()->exists() ? route('exam.navigation.index', $course) : null,
            'funk-oral' => in_array($selected, $funkCatalogs, true) ? route('funk.oral.index', ['course' => $course, 'teil' => $selected]) : null,
            'funk-praxis' => in_array($selected, $funkCatalogs, true) ? route('funk.praxis.index', ['course' => $course, 'teil' => $selected]) : null,
        ];

        return view('exam-booking', [
            'course' => $course,
            'tabs' => collect($pages)->mapWithKeys(fn ($variant, $key) => [ExamBooking::page($key)['tab'] => route('exam-booking', ['course' => $course, 'teil' => $key])])->all(),
            'activeTab' => $page['tab'],
            'info' => $page,
            'variants' => ExamBooking::variants($selected, $pages[$selected]),
            'providers' => ExamBooking::providers(),
            'examParts' => array_map(fn (array $part) => $part + ['url' => $part['link'] ? $links[$part['link']] : null], $page['exam_parts']),
        ]);
    }
}
