<?php

namespace App\Api;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\VideoCourseLessons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Kurszugang und Kurs-Eigenschaften für die Mobile-API. */
class CourseAccess
{
    public function __construct(private EntitlementService $entitlements) {}

    /** Kurs laden und Zugang prüfen (ENTITLEMENT_REQUIRED / ENTITLEMENT_EXPIRED). */
    public function require(Tenant $tenant, User $user, string $courseId): CourseDefinition
    {
        $course = CourseDefinition::find($courseId);
        if (! $course) {
            throw ApiProblem::notFound('Kurs');
        }
        if (! $this->entitlements->hasAccess($tenant, $user, $course)) {
            $status = $this->status($tenant, $user, $course)['status'];
            throw $status === 'expired'
                ? new ApiProblem(403, 'ENTITLEMENT_EXPIRED', 'Kurszugang abgelaufen', 'Für diesen Kurs besteht derzeit kein aktiver Zugang.')
                : new ApiProblem(403, 'ENTITLEMENT_REQUIRED', 'Kein Kurszugang', 'Dieser Kurs ist für dich nicht freigeschaltet.');
        }

        return $course;
    }

    /** @return array{status: string, valid_from: ?string, valid_until: ?string} */
    /**
     * @param  ?Collection<int, Entitlement>  $preloaded  alle Zugänge der Person
     *                                                    in dieser Schule (spart eine Abfrage je Kurs in Listen)
     */
    public function status(Tenant $tenant, User $user, CourseDefinition $course, ?Entitlement $active = null, ?Collection $preloaded = null): array
    {
        $entitlement = $active ?? ($preloaded !== null
            ? $preloaded->where('course_id', $course->id)->sortBy(fn (Entitlement $e) => [$e->status === 'active' ? 0 : 1, $e->valid_until ? -$e->valid_until->getTimestamp() : PHP_INT_MIN])->first()
            : Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)
                ->orderByRaw("status = 'active' desc")->orderByRaw('valid_until desc nulls first')->first());
        if (! $entitlement) {
            return ['status' => 'not_granted', 'valid_from' => null, 'valid_until' => null];
        }
        $status = $entitlement->status;
        if ($status === 'active' && $entitlement->valid_until && $entitlement->valid_until->isPast()) {
            $status = 'expired';
        }

        return [
            'status' => $status,
            'valid_from' => Present::time($entitlement->valid_from),
            'valid_until' => Present::time($entitlement->valid_until),
        ];
    }

    /**
     * Aktuell gültige Fragen eines Kurses (Modulfragen), 5 Minuten zwischen-
     * gespeichert -- Inhalte ändern sich selten, Listen fragen je Kurs.
     *
     * @return Collection<int, string>
     */
    public function validQuestionIds(CourseDefinition $course): Collection
    {
        return collect(Cache::remember('api-course-valid-qids:'.$course->id, 300, function () use ($course) {
            $course->loadMissing('modules.questions');

            return ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions))->values()->all();
        }));
    }

    /** @return list<string> */
    public function capabilities(CourseDefinition $course): array
    {
        return Cache::remember('api-course-capabilities:'.$course->id, 300, function () use ($course) {
            $hasQuestions = $course->contentQuestionIds()->isNotEmpty();

            return array_values(array_filter([
                $hasQuestions ? 'smarttrainer' : null,
                $hasQuestions ? 'favorites' : null,
                $course->hasStartableExam() ? 'exam' : null,
                VideoCourseLessons::ordered($course)->isNotEmpty() ? 'video' : null,
                $course->navigationTasks()->exists() ? 'navigation' : null,
                $course->praxisTasks()->exists() ? 'praxis' : null,
            ]));
        });
    }
}
