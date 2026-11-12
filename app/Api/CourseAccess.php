<?php

namespace App\Api;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\FunkTraining;
use App\Support\VideoCourseLessons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Kurszugang und Kurs-Eigenschaften für die Mobile-API. */
class CourseAccess
{
    /** Frisch 5 Minuten, danach bis 1 Stunde als veralteter Wert sofort lieferbar (Neuberechnung nach der Antwort). */
    public const CACHE_TTL = [300, 3600];

    public static function cacheKey(string $what, CourseDefinition $course): string
    {
        return "api-course-{$what}:{$course->id}";
    }

    /** Zwischenspeicher eines Kurses neu berechnen (app:warm-course-cache). */
    public function warm(CourseDefinition $course): void
    {
        foreach (['valid-qids', 'capabilities'] as $what) {
            Cache::forget(self::cacheKey($what, $course));
            Cache::forget('illuminate:cache:flexible:created:'.self::cacheKey($what, $course));
        }
        $this->validQuestionIds($course);
        $this->capabilities($course);
    }

    public function __construct(private EntitlementService $entitlements) {}

    /** Kurs laden und Zugang prüfen (ENTITLEMENT_REQUIRED / ENTITLEMENT_EXPIRED). */
    public function require(Tenant $tenant, User $user, string $courseId, ?string $capability = null): CourseDefinition
    {
        $course = CourseDefinition::find($courseId);
        if (! $course) {
            throw ApiProblem::notFound('Kurs');
        }
        $hasAccess = $capability === null
            ? $this->entitlements->hasAccess($tenant, $user, $course)
            : $this->entitlements->hasCapabilityAccess($tenant, $user, $course, $capability);
        if (! $hasAccess) {
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
        $rows = $preloaded !== null
            ? $preloaded->where('course_id', $course->id)->values()
            : Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('course_id', $course->id)->get();
        $now = now();
        $valid = $rows->filter(fn (Entitlement $e) => $e->status === 'active'
            && (! $e->valid_from || $e->valid_from <= $now)
            && (! $e->valid_until || $e->valid_until > $now))->values();

        if ($valid->isNotEmpty()) {
            $status = 'active';
            $froms = $valid->pluck('valid_from')->filter();
            $unbounded = $valid->contains(fn (Entitlement $e) => $e->valid_until === null);
            $until = $unbounded ? null : $valid->max('valid_until');
        } else {
            $froms = collect();
            $until = null;
            $statuses = $rows->map(function (Entitlement $e) use ($now) {
                return $e->status === 'active' && $e->valid_until && $e->valid_until <= $now ? 'expired' : $e->status;
            });
            $status = 'not_granted';
            foreach (['pending', 'suspended', 'expired', 'revoked'] as $candidate) {
                if ($statuses->contains($candidate)) {
                    $status = $candidate;
                    break;
                }
            }
        }

        $questionAccess = $valid->filter(fn (Entitlement $e) => $e->capabilities === null || in_array('smarttrainer', $e->capabilities, true));
        $unboundedQuestionAccess = $questionAccess->contains(fn (Entitlement $e) => $e->valid_until === null);
        $questionExpiry = $unboundedQuestionAccess ? null : $questionAccess->max('valid_until');
        $expiresSoon = $questionExpiry !== null && $questionExpiry->lte($now->copy()->addDays(Entitlement::EXPIRY_WARNING_DAYS));
        $renewUrl = $expiresSoon
            ? \App\Models\CourseWebshopLink::where('tenant_id', $tenant->id)->where('course_id', $course->id)->value('url')
            : null;

        return [
            'status' => $status,
            'valid_from' => Present::time($froms->isNotEmpty() ? $froms->min() : null),
            'valid_until' => Present::time($until),
            'trial' => false,
            'purchased' => $valid->isNotEmpty(),
            'limits' => (object) [],
            'expires_soon' => $expiresSoon,
            'expires_at' => Present::time($questionExpiry),
            'renew_url' => $renewUrl,
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
        // Review: stale-while-revalidate -- nach 5 Minuten wird der alte Wert
        // noch bis zu einer Stunde sofort geliefert und nach der Antwort
        // erneuert; app:warm-course-cache füllt den Cache vorab.
        return collect(Cache::flexible(self::cacheKey('valid-qids', $course), self::CACHE_TTL, function () use ($course) {
            $course->loadMissing('modules.questions');

            return ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions))->values()->all();
        }));
    }

    /** @return list<string> */
    public function capabilities(CourseDefinition $course): array
    {
        return Cache::flexible(self::cacheKey('capabilities', $course), self::CACHE_TTL, function () use ($course) {
            $hasQuestions = $course->contentQuestionIds()->isNotEmpty();

            return array_values(array_filter([
                $hasQuestions ? 'smarttrainer' : null,
                $hasQuestions ? 'favorites' : null,
                $course->hasStartableExam() ? 'exam' : null,
                VideoCourseLessons::ordered($course)->isNotEmpty() ? 'video' : null,
                $course->navigationTasks()->exists() ? 'navigation' : null,
                $course->praxisTasks()->exists() ? 'praxis' : null,
                FunkTraining::catalogsFor($course) !== [] ? 'funk' : null,
            ]));
        });
    }
}
