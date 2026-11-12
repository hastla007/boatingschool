<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\CourseDefinition;
use App\Support\CanonicalUrl;
use App\Support\ExamBooking;
use Illuminate\Http\JsonResponse;

class ExamRegistrationController extends Controller
{
    /** Read-only exam booking guidance; registration itself remains with the exam authority. */
    public function show(string $courseId): JsonResponse
    {
        $course = CourseDefinition::find($courseId);
        if (! $course) {
            throw ApiProblem::notFound('Kurs');
        }

        $pages = ExamBooking::pagesFor($course);
        if ($pages === []) {
            throw ApiProblem::notFound('Prüfungsanleitung');
        }

        $providers = ExamBooking::providers();
        $guides = [];
        foreach ($pages as $key => $firstVariant) {
            $page = ExamBooking::page($key);
            $guides[] = [
                'key' => (string) $key,
                'tab' => (string) $page['tab'],
                'title' => (string) $page['title'],
                'intro' => $page['intro'] ?? null,
                'fee' => $page['fee'] ?? null,
                'fee_note' => null,
                'lead_time' => $page['lead_time'] ?? null,
                'min_age' => $page['min_age'] ?? null,
                'apply' => $page['apply'] ?? null,
                'deadline' => $page['deadline'] ?? null,
                'documents' => $page['documents'] ?? [],
                'variants' => array_map(static function (array $variant): array {
                    $variant['pass_text'] = $variant['pass'] ?? null;
                    unset($variant['pass']);

                    return $variant;
                }, ExamBooking::variants($key, $firstVariant)),
                'exam_parts' => $page['exam_parts'] ?? [],
                'faq' => $page['faq'] ?? [],
                'providers' => $providers,
            ];
        }

        $committees = collect($providers)->map(fn (array $provider, int $index) => [
            'id' => (string) ($provider['name'] ?? $index),
            'label' => (string) ($provider['full'] ?? $provider['name'] ?? 'Prüfungsverband'),
            'commission' => (string) ($provider['full'] ?? $provider['name'] ?? 'Prüfungsverband'),
            'location_type' => null,
            'contact_person' => null,
            'address' => null,
            'postal_code' => null,
            'city' => null,
            'phone' => null,
            'fax' => null,
            'email' => null,
            'website' => $provider['url'] ?? null,
            'office_hours' => null,
            'note' => $provider['text'] ?? null,
            'course_codes' => [$course->code],
            'states' => [],
            'rank' => 1,
            'matches' => true,
        ])->values();

        $webUrl = route('exam-booking', ['course' => $course]);
        $webUrl = CanonicalUrl::root().(parse_url($webUrl, PHP_URL_PATH) ?: '');

        return response()->json(['data' => [
            'course_id' => (string) $course->id,
            'web_url' => $webUrl,
            'registration_in_app' => false,
            'guides' => $guides,
            'committees' => $committees,
        ]]);
    }
}
