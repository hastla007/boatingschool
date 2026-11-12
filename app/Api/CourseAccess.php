<?php

namespace App\Api;

use App\Models\ContentQuestion;
use App\Models\CourseAccessRule;
use App\Models\CourseDefinition;
use App\Models\CourseWebshopLink;
use App\Models\Entitlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EntitlementService;
use App\Support\FunkTraining;
use App\Support\VideoCourseLessons;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/** Kurszugang und Kurs-Eigenschaften für die Mobile-API. */
class CourseAccess
{
    /** Frisch 5 Minuten, danach bis 1 Stunde als veralteter Wert sofort lieferbar (Neuberechnung nach der Antwort). */
    public const CACHE_TTL = [300, 3600];

    private array $scopeCache = [];

    /** WP access-rule feature code for each mobile capability. */
    private const RULE_FEATURES = [
        'smarttrainer' => 'question_bank',
        'favorites' => 'question_bank',
        'exam' => 'exam_simulation',
        'video' => 'video_course',
        'navigation' => 'navigation_trainer',
        'praxis' => 'praxis_trainer',
        'funk' => 'radio_training',
        'explanations' => 'learning_explanations',
        'downloads' => 'downloads',
    ];

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
            : $this->hasFeatureAccess($tenant, $user, $course, $capability);
        if (! $hasAccess) {
            $status = $this->status($tenant, $user, $course)['status'];
            throw $status === 'expired'
                ? new ApiProblem(403, 'ENTITLEMENT_EXPIRED', 'Kurszugang abgelaufen', 'Für diesen Kurs besteht derzeit kein aktiver Zugang.')
                : new ApiProblem(403, 'ENTITLEMENT_REQUIRED', 'Kein Kurszugang', 'Dieser Kurs ist für dich nicht freigeschaltet.');
        }

        return $course;
    }

    /** @return array{full:bool,limit:?int} */
    public function featureScope(Tenant $tenant, User $user, CourseDefinition $course, string $capability): array
    {
        $key = implode(':', [$tenant->id, $user->id, $course->id, $capability]);
        if (array_key_exists($key, $this->scopeCache)) {
            return $this->scopeCache[$key];
        }

        $feature = self::RULE_FEATURES[$capability] ?? null;
        $entitlementCapability = in_array($capability, ['favorites', 'explanations'], true) ? 'smarttrainer' : $capability;
        $full = $this->entitlements->hasCapabilityAccess($tenant, $user, $course, $entitlementCapability);
        if ($capability === 'favorites') {
            $full = $full || $this->entitlements->hasCapabilityAccess($tenant, $user, $course, 'favorites');
        }
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

        return $this->scopeCache[$key] = [
            'full' => $full,
            'limit' => $full || $limit === null ? null : (int) $limit,
        ];
    }

    public function hasFeatureAccess(Tenant $tenant, User $user, CourseDefinition $course, string $capability): bool
    {
        $scope = $this->featureScope($tenant, $user, $course, $capability);

        return $scope['full'] || (int) ($scope['limit'] ?? 0) > 0;
    }

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

        $status = 'not_granted';
        $froms = collect();
        $until = null;
        if ($valid->isNotEmpty()) {
            $status = 'active';
            $froms = $valid->pluck('valid_from')->filter();
            $unbounded = $valid->contains(fn (Entitlement $e) => $e->valid_until === null);
            $until = $unbounded ? null : $valid->max('valid_until');
        } else {
            $statuses = $rows->map(function (Entitlement $e) use ($now) {
                return $e->status === 'active' && $e->valid_until && $e->valid_until <= $now ? 'expired' : $e->status;
            });
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
        $hasFullScope = $scopes->contains(fn (array $scope) => $scope['full']);
        $limits = $scopes->map(fn (array $scope) => (int) ($scope['limit'] ?? 0))
            ->filter(fn (int $limit) => $limit > 0)->all();
        if ($hasFullScope) {
            $status = 'active';
        } elseif ($limits !== []) {
            $status = 'limited';
        }

        $questionAccess = $valid->filter(fn (Entitlement $e) => $e->capabilities === null
            || in_array('smarttrainer', $e->capabilities, true) || in_array('favorites', $e->capabilities, true));
        $unboundedQuestionAccess = $questionAccess->contains(fn (Entitlement $e) => $e->valid_until === null);
        $questionExpiry = $unboundedQuestionAccess ? null : $questionAccess->max('valid_until');
        $expiresSoon = $questionExpiry !== null && $questionExpiry->lte($now->copy()->addDays(Entitlement::EXPIRY_WARNING_DAYS));
        $renewUrl = $expiresSoon
            ? CourseWebshopLink::where('tenant_id', $tenant->id)->where('course_id', $course->id)->value('url')
            : null;

        return [
            'status' => $status,
            'valid_from' => Present::time($froms->isNotEmpty() ? $froms->min() : null),
            'valid_until' => Present::time($until),
            'trial' => ! $hasFullScope && $limits !== [],
            'purchased' => $valid->isNotEmpty(),
            'limits' => (object) $limits,
            'expires_soon' => $expiresSoon,
            'expires_at' => Present::time($questionExpiry),
            'renew_url' => $renewUrl,
        ];
    }

    /** Apply WP's first_items rule to an already deterministically ordered resource list. */
    public function limitItems(Tenant $tenant, User $user, CourseDefinition $course, string $capability, Collection $items): Collection
    {
        $scope = $this->featureScope($tenant, $user, $course, $capability);

        return $scope['full'] ? $items->values() : $items->take(max(0, (int) ($scope['limit'] ?? 0)))->values();
    }

    /** Check direct-ID access against the same ordered list used by the collection endpoint. */
    public function allowsLimitedItem(Tenant $tenant, User $user, CourseDefinition $course, string $capability, Collection $orderedItems, string $id): bool
    {
        return $this->limitItems($tenant, $user, $course, $capability, $orderedItems)
            ->contains(fn ($item) => (string) $item->id === $id);
    }

    /**
     * Aktuell gültige Fragen eines Kurses (Modulfragen), 5 Minuten zwischen-
     * gespeichert -- Inhalte ändern sich selten, Listen fragen je Kurs.
     *
     * @return Collection<int, string>
     */
    public function validQuestionIds(CourseDefinition $course): Collection
    {
        return collect(Cache::flexible(self::cacheKey('valid-qids', $course), self::CACHE_TTL, function () use ($course) {
            $course->loadMissing('modules.questions');

            return ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions))->values()->all();
        }));
    }

    /** Return the unlock timestamp for a module-level drip rule, if one applies. */
    public function dripUnlockAt(Tenant $tenant, User $user, CourseDefinition $course, string $capability, string $moduleCode): ?Carbon
    {
        $feature = self::RULE_FEATURES[$capability] ?? null;
        if ($feature === null || trim($moduleCode) === '') {
            return null;
        }

        $now = now();
        $rule = CourseAccessRule::where('tenant_id', $tenant->id)->where('course_id', $course->id)
            ->where('feature_code', $feature)->where('access_type', 'drip')->where('module_code', $moduleCode)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
            ->orderBy('limit_value')->first(['limit_value']);
        if (! $rule) {
            return null;
        }

        $entitlementCapability = in_array($capability, ['favorites', 'explanations'], true) ? 'smarttrainer' : $capability;
        $starts = Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('course_id', $course->id)->where('status', 'active')->get()
            ->filter(fn (Entitlement $entitlement) => $entitlement->capabilities === null
                || in_array($entitlementCapability, $entitlement->capabilities, true))
            ->map(fn (Entitlement $entitlement) => $entitlement->valid_from ?: $entitlement->created_at)
            ->filter(fn ($start) => $start !== null && $start <= $now);
        $startedAt = $starts->isNotEmpty() ? Carbon::parse($starts->min()) : ($user->created_at ? Carbon::parse($user->created_at) : $now);

        return $startedAt->copy()->addDays((int) $rule->limit_value);
    }

    public function isDripLocked(Tenant $tenant, User $user, CourseDefinition $course, string $capability, string $moduleCode): bool
    {
        return ($unlockAt = $this->dripUnlockAt($tenant, $user, $course, $capability, $moduleCode)) !== null
            && $unlockAt->isFuture();
    }

    /** Questions available to a partial question-bank entitlement, keeping the first N per module. */
    public function accessibleQuestionIds(Tenant $tenant, User $user, CourseDefinition $course): Collection
    {
        $scope = $this->featureScope($tenant, $user, $course, 'smarttrainer');
        $limit = $scope['full'] ? null : (int) ($scope['limit'] ?? 0);
        if (! $scope['full'] && $limit < 1) {
            return collect();
        }

        $course->loadMissing('modules.questions');
        $allowed = collect();
        foreach ($course->modules as $module) {
            if ($this->isDripLocked($tenant, $user, $course, 'smarttrainer', (string) $module->code)) {
                continue;
            }
            $valid = ContentQuestion::currentlyValidIds($module->questions);

            $allowed = $allowed->merge($limit === null ? $valid : $valid->take($limit));
        }

        return $allowed->unique()->values();
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
