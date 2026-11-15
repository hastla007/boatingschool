<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\CourseAccess;
use App\Http\Controllers\Controller;
use App\Support\CanonicalUrl;
use App\Support\ExamBooking;
use App\Support\ExamRegistration;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anmeldung zur amtlichen Prüfung, nur lesend. Wie im Web
 * (ExamBookingController/ExamRegistration): meldet die Bootsschule an
 * (mode=school), gilt ihr Termintool; meldet sich der Teilnehmer selbst an
 * (mode=self), liefert die API die Anleitung der Seite "Prüfung buchen".
 * Die Anmeldung selbst läuft nie über die App.
 */
class ExamRegistrationController extends Controller
{
    public function __construct(private TenantContext $tenantContext, private CourseAccess $access) {}

    public function show(Request $request, string $courseId): JsonResponse
    {
        $tenant = $this->tenantContext->tenant();
        $user = $request->user();
        $course = $this->access->require($tenant, $user, $courseId);
        $mode = ExamRegistration::modeFor($tenant, $course);
        $showGuide = ! ExamRegistration::usesAppointmentTool($tenant, $course, $user);

        $guides = [];
        $providers = ExamBooking::providers();
        if ($showGuide) {
            foreach (ExamBooking::pagesFor($course) as $key => $firstVariant) {
                $page = ExamBooking::page($key);
                $guides[] = [
                    'key' => (string) $key,
                    'tab' => (string) $page['tab'],
                    'title' => (string) $page['title'],
                    'intro' => $page['intro'] ?? null,
                    'fee' => $page['fee'] ?? null,
                    'lead_time' => $page['lead_time'] ?? null,
                    'min_age' => $page['min_age'] ?? null,
                    'apply' => $page['apply'] ?? null,
                    'deadline' => $page['deadline'] ?? null,
                    'documents' => $page['documents'] ?? [],
                    'variants' => array_values(array_map(static function (array $variant): array {
                        $variant['pass_text'] = $variant['pass'] ?? null;
                        unset($variant['pass']);

                        return $variant;
                    }, ExamBooking::variants($key, $firstVariant))),
                    'exam_parts' => array_map(fn (array $part) => array_diff_key($part, ['link' => true]), $page['exam_parts'] ?? []),
                    'faq' => $page['faq'] ?? [],
                ];
            }
        }

        return response()->json(['data' => [
            'course_id' => (string) $course->id,
            'mode' => $mode,
            'uses_appointment_tool' => ! $showGuide,
            'registration_in_app' => false,
            'web_url' => $showGuide ? CanonicalUrl::root($tenant).(parse_url(route('exam-booking', ['course' => $course]), PHP_URL_PATH) ?: '') : null,
            'guides' => $guides,
            'providers' => $showGuide ? array_values($providers) : [],
        ]]);
    }
}
