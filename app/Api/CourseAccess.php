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
    public const CACHE_TTL = [300, 3600];\n\n    private array $scopeCache = [];\n\n    private const RULE_FEATURES = [\n        'smarttrainer' => 'question_bank', 'favorites' => 'question_bank',\n        'exam' => 'exam_simulation', 'video' => 'video_course',\n        'navigation' => 'navigation_trainer', 'praxis' => 'praxis_trainer',\n        'funk' => 'radio_training', 'explanations' => 'learning_explanations',\n    ];

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

    /** Active full or limited access for one mobile capability. */
    public function featureScope(Tenant $tenant, User $user, CourseDefinition $course, string $capability): array
    {
        $key = implode(':', [$tenant->id, $user->id, $course->id, $capability]);
        if (isset($this->scopeCache[$key])) {
            return $this->scopeCache[$key];
        }

        $feature = self::RULE_FEATURES[$capability] ?? null;
        $entitlementCapability = in_array($capability, ['favorites', 'explanations'], true) ? 'smarttrainer' : $capability;
        $full = $this->entitlements->hasCapabilityAccess($tenant, $user, $course, $entitlementCapability);
        $limit = null;
        if ($feature !== null) {
            $now = now();
            $rules = CourseAccessRule::where('tenant_id', $tenant->id)->where('course_id', $course->id)
                ->where('feature_code', $feature)->whereNull('module_code')
                ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
                ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
                ->get(['access_type', 'limit_value']);
            $full = $full || $rules->contains(fn (CourseAccessRule $rule) => $rule->access_type === 'free');
            if (! $full) {
                $limit = $rules->where('access_type', 'free_limited')->max('limit_value');
            }
        }

        return $this->scopeCache[$key] = ['full' => $full, 'limit' => $full ? null : ($limit === null ? null : (int) $limit)];
    }

    public function hasFeatureAccess(Tenant $tenant, User $user, CourseDefinition $course, string $capability): bool
    {
        $scope = $this->featureScope($tenant, $user, $course, $capability);

        return $scope['full'] || (int) ($scope['limit'] ?? 0) > 0;
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

        $capabilities = $this->capabilities($course);
        if ($capabilities === []) {
            $capabilities = ['smarttrainer'];
        }
        $scopes = collect(array_unique($capabilities))->mapWithKeys(fn (string $cap) => [
            $cap => $this->featureScope($tenant, $user, $course, $cap),
        ]);
        $hasFullScope = $scopes->contains(fn ($scope) => $scope['full']);
        $limits = $scopes->map(fn ($scope) => (int) ($scope['limit'] ?? 0))
            ->filter(fn (int $limit) => $limit > 0)->all();
        if ($hasFullScope) {
            $status = 'active';
        } elseif ($limits !== []) {
            $status = 'limited';
        }
