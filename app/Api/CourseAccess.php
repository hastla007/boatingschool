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
use App\Support\DisabledCourses;
use App\Support\VideoCourseLessons;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use WeakReference;

/**
 * Kurszugang und Kurs-Eigenschaften für die Mobile-API.
 *
 * Zugriffsmodell (Parität zur WordPress-App):
 *  - Kurszugang: aktives Entitlement ohne Einschränkung (capabilities = null).
 *  - Funktionszugang (featureScope): Entitlement mit passender Capability,
 *    oder eine Regel aus course_access_rule der Schule: „free“ (voll),
 *    „free_limited“ (die ersten N Einträge) bzw. „drip“ (Modul erst N Tage
 *    nach Zugangsbeginn).
 *  - config('mobile_api.default_access') = 'logged_in' gibt jedem
 *    eingeloggten Mitglied Vollzugriff auf angebotene Kurse (WP-Schalter,
 *    Standard: 'entitlement').
 *
 * Schnittstelle für Endpunkte (Kursdateien, Lerngruppen, Trainer):
 *  - require($tenant, $user, $courseId, ?$capability) -- 404/403 werfen
 *  - featureScope()/hasFeatureAccess() -- {full, limit} je Capability
 *  - limitItems()/allowsLimitedItem() -- „erste N“ auf geordnete Listen
 *  - dripUnlockAt()/isDripLocked() -- Modulsperre nach Zugangsbeginn
 *  - accessibleQuestionIds() -- freigegebene Fragen (Limit + Drip)
 *  - forget() -- Zwischenspeicher leeren, nachdem im selben Request
 *    Zugänge oder Regeln geändert wurden.
 *
 * Alle Daten je Person/Schule (aktive Zugänge, Zugangsregeln, gesperrte
 * Kurse, Webshop-Links) werden je Request genau einmal geladen -- auch
 * eine Kursliste mit allen Kursen und Capabilities kostet so nur eine
 * Handvoll Abfragen.
 */
class CourseAccess
{
    /** Frisch 5 Minuten, danach bis 1 Stunde als veralteter Wert sofort lieferbar (Neuberechnung nach der Antwort). */
    public const CACHE_TTL = [300, 3600];

    public const CONTENT_VERSION_KEY = 'api-content-version';

    /** Capabilities, die ein Entitlement einschränkend tragen kann (siehe Migration 000598/000606). */
    public const ENTITLEMENT_CAPABILITIES = ['smarttrainer', 'favorites', 'exam', 'video', 'navigation', 'praxis', 'funk', 'downloads'];

    /** WP-Funktionscode (course_access_rule.feature_code) je Mobile-Capability. */
    public const RULE_FEATURES = [
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

    /** Request-Zwischenspeicher; gilt nur für den Request in $memoRequest. */
    private array $memo = [];

    private ?WeakReference $memoRequest = null;

    /**
     * Schlüssel mit Inhaltsversion und Tag: jede Änderung im Superadmin-Bereich
     * erhöht die Version (AuditSuperadminChanges), und Gültigkeitsfenster von
     * Fragen („gültig ab/bis“) greifen ab Mitternacht -- die App sieht so
     * dieselben Fragen wie das Web statt bis zu einer Stunde alter Werte.
     */
    public static function cacheKey(string $what, CourseDefinition $course): string
    {
        // memo(): je Request nur einmal aus dem Cache lesen.
        $version = (int) Cache::memo()->get(self::CONTENT_VERSION_KEY, 1);

        return "api-course-{$what}:{$course->id}:v{$version}:".now('Europe/Berlin')->toDateString();
    }

    /**
     * Audit ARC-V2: atomar erhöhen (zwei gleichzeitige Änderungen ergaben
     * mit Lesen + Schreiben sonst dieselbe Version). add() legt die Version
     * beim ersten Mal an (Wert 1 = Standard von cacheKey()).
     */
    public static function bumpContentVersion(): void
    {
        Cache::add(self::CONTENT_VERSION_KEY, 1, now()->addYears(10));
        if (Cache::memo()->increment(self::CONTENT_VERSION_KEY) === false) {
            Cache::memo()->forever(self::CONTENT_VERSION_KEY, (int) Cache::get(self::CONTENT_VERSION_KEY, 1) + 1);
        }
    }

    /** Zwischenspeicher eines Kurses neu berechnen (app:warm-course-cache). */
    public function warm(CourseDefinition $course): void
    {
        $key = self::cacheKey('data', $course);
        Cache::forget($key);
        Cache::forget('illuminate:cache:flexible:created:'.$key);
        $this->forget();
        $this->courseData($course);
    }

    public function __construct(private EntitlementService $entitlements) {}

    /**
     * Kurs laden und Zugang prüfen (ENTITLEMENT_REQUIRED / ENTITLEMENT_EXPIRED).
     * Ohne $capability ist voller Kurszugang nötig, mit $capability reicht
     * Funktionszugang (auch eingeschränkt, siehe limitItems()).
     */
    public function require(Tenant $tenant, User $user, string $courseId, ?string $capability = null): CourseDefinition
    {
        $course = CourseDefinition::find($courseId);
        if (! $course) {
            throw ApiProblem::notFound('Kurs');
        }

        $hasAccess = $capability === null
            ? $this->hasCourseAccess($tenant, $user, $course)
            : $this->hasFeatureAccess($tenant, $user, $course, $capability);
        if (! $hasAccess) {
            $status = $this->status($tenant, $user, $course)['status'];
            throw $status === 'expired'
                ? new ApiProblem(403, 'ENTITLEMENT_EXPIRED', 'Kurszugang abgelaufen', 'Für diesen Kurs besteht derzeit kein aktiver Zugang.')
                : new ApiProblem(403, 'ENTITLEMENT_REQUIRED', 'Kein Kurszugang', 'Dieser Kurs ist für dich nicht freigeschaltet.');
        }

        return $course;
    }

    /** Zwischenspeicher leeren (nach Änderungen an Zugängen/Regeln im selben Request). */
    public function forget(): void
    {
        $this->memo = [];
        $this->memoRequest = null;
    }

    /** Voller Kurszugang: uneingeschränktes aktives Entitlement (oder default_access = logged_in). */
    public function hasCourseAccess(Tenant $tenant, User $user, CourseDefinition $course): bool
    {
        if ($this->loggedInDefault() && $this->isOffered($tenant, $course)) {
            return true;
        }

        return $this->activeEntitlements($tenant, $user)->contains(
            fn (Entitlement $e) => $e->course_id === $course->id && $e->capabilities === null
        );
    }

    /**
     * Umfang einer Funktion: full = uneingeschränkt, sonst limit = die ersten
     * N Einträge (free_limited) bzw. null = kein Zugang.
     *
     * @return array{full: bool, limit: ?int}
     */
    public function featureScope(Tenant $tenant, User $user, CourseDefinition $course, string $capability): array
    {
        return $this->remember("scope:{$tenant->id}:{$user->id}:{$course->id}:{$capability}", function () use ($tenant, $user, $course, $capability) {
            $offered = $this->isOffered($tenant, $course);
            $full = ($this->loggedInDefault() && $offered)
                || $this->hasCapabilityEntitlement($tenant, $user, $course, $this->entitlementCapability($capability))
                || ($capability === 'favorites' && $this->hasCapabilityEntitlement($tenant, $user, $course, 'favorites'));

            $limit = null;
            $feature = self::RULE_FEATURES[$capability] ?? null;
            if (! $full && $feature !== null && $offered) {
                $now = now();
                $rules = $this->rules($tenant)->get($course->id, collect())->filter(fn (CourseAccessRule $rule) => $rule->feature_code === $feature
                    && $rule->module_code === null
                    && ($rule->valid_from === null || $rule->valid_from <= $now)
                    && ($rule->valid_until === null || $rule->valid_until >= $now));
                $full = $rules->contains(fn (CourseAccessRule $rule) => $rule->access_type === 'free');
                if (! $full) {
                    $limit = $rules->where('access_type', 'free_limited')->max('limit_value');
                }
            }

            return ['full' => $full, 'limit' => $full || $limit === null ? null : (int) $limit];
        });
    }

    public function hasFeatureAccess(Tenant $tenant, User $user, CourseDefinition $course, string $capability): bool
    {
        $scope = $this->featureScope($tenant, $user, $course, $capability);

        return $scope['full'] || (int) ($scope['limit'] ?? 0) > 0;
    }

    /** WP-Regel first_items auf eine bereits deterministisch geordnete Liste anwenden. */
    public function limitItems(Tenant $tenant, User $user, CourseDefinition $course, string $capability, Collection $items): Collection
    {
        $scope = $this->featureScope($tenant, $user, $course, $capability);

        return $scope['full'] ? $items->values() : $items->take(max(0, (int) ($scope['limit'] ?? 0)))->values();
    }

    /** Direktzugriff per ID gegen dieselbe geordnete Liste wie der Listen-Endpunkt prüfen. */
    public function allowsLimitedItem(Tenant $tenant, User $user, CourseDefinition $course, string $capability, Collection $orderedItems, string $id): bool
    {
        return $this->limitItems($tenant, $user, $course, $capability, $orderedItems)
            ->contains(fn ($item) => (string) $item->id === $id);
    }

    /**
     * @param  ?Collection<int, Entitlement>  $preloaded  alle Zugänge der Person
     *                                                    in dieser Schule (spart eine Abfrage je Kurs in Listen)
     * @return array{status: string, valid_from: ?string, valid_until: ?string, trial: bool, purchased: bool, limits: object, expires_soon: bool, expires_at: ?string, renew_url: ?string}
     */
    public function status(Tenant $tenant, User $user, CourseDefinition $course, ?Entitlement $active = null, ?Collection $preloaded = null): array
    {
        $rows = ($preloaded ?? $this->entitlementRows($tenant, $user))->where('course_id', $course->id)->values();
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
            $until = $valid->contains(fn (Entitlement $e) => $e->valid_until === null) ? null : $valid->max('valid_until');
        } else {
            $statuses = $rows->map(fn (Entitlement $e) => $e->status === 'active' && $e->valid_until && $e->valid_until <= $now ? 'expired' : $e->status);
            foreach (['pending', 'suspended', 'expired', 'revoked'] as $candidate) {
                if ($statuses->contains($candidate)) {
                    $status = $candidate;
                    break;
                }
            }
            // Letztes abgelaufenes Fenster weiter anzeigen (wie bisher).
            $latest = $rows->sortByDesc(fn (Entitlement $e) => $e->valid_until?->getTimestamp() ?? PHP_INT_MAX)->first();
            $froms = collect([$latest?->valid_from])->filter();
            $until = $latest?->valid_until;
        }

        $capabilities = array_values(array_intersect(['smarttrainer', 'exam', 'video', 'navigation', 'praxis', 'funk'], $this->capabilities($course))) ?: ['smarttrainer'];
        $scopes = collect($capabilities)->mapWithKeys(fn (string $cap) => [$cap => $this->featureScope($tenant, $user, $course, $cap)]);
        $hasFullScope = $scopes->contains(fn (array $scope) => $scope['full']);
        $limits = $scopes->map(fn (array $scope) => (int) ($scope['limit'] ?? 0))->filter(fn (int $limit) => $limit > 0)->all();
        if ($hasFullScope) {
            $status = 'active';
        } elseif ($limits !== []) {
            $status = 'limited';
        }

        $questionAccess = $valid->filter(fn (Entitlement $e) => $e->capabilities === null
            || in_array('smarttrainer', $e->capabilities, true) || in_array('favorites', $e->capabilities, true));
        $questionExpiry = $questionAccess->contains(fn (Entitlement $e) => $e->valid_until === null) ? null : $questionAccess->max('valid_until');
        $expiresSoon = $questionExpiry !== null && $questionExpiry->lte($now->copy()->addDays(Entitlement::EXPIRY_WARNING_DAYS));

        return [
            'status' => $status,
            'valid_from' => Present::time($froms->isNotEmpty() ? $froms->min() : null),
            'valid_until' => Present::time($until),
            'trial' => ! $hasFullScope && $limits !== [],
            'purchased' => $valid->isNotEmpty(),
            'limits' => (object) $limits,
            'expires_soon' => $expiresSoon,
            'expires_at' => Present::time($questionExpiry),
            'renew_url' => $expiresSoon ? $this->webshopUrls($tenant)->get($course->id) : null,
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
        return collect($this->courseData($course)['valid']);
    }

    /**
     * Gültige Fragen je Modul in Kursreihenfolge (für „erste N je Modul“ und
     * Drip), zwischengespeichert wie validQuestionIds().
     *
     * @return array<int, array{id: string, code: string, ids: list<string>}>
     */
    public function moduleQuestionIds(CourseDefinition $course): array
    {
        return $this->courseData($course)['modules'];
    }

    /**
     * Inhaltliche Daten eines Kurses (gültige Fragen, Fragen je Modul,
     * Funktionen) als EIN Cache-Eintrag, je Request nur einmal gelesen.
     *
     * Review: stale-while-revalidate -- nach 5 Minuten wird der alte Wert
     * noch bis zu einer Stunde sofort geliefert und nach der Antwort
     * erneuert; app:warm-course-cache füllt den Cache vorab.
     * Audit ARC-5: vorher drei Schlüssel je Kurs und kein Request-Speicher --
     * mit dem Datenbank-Cache über die Hälfte aller Abfragen einer Kursliste.
     * Der Schlüssel trägt weiter Inhaltsversion und Tag (Invalidierung).
     *
     * @return array{valid: list<string>, modules: array<int, array{id: string, code: string, ids: list<string>}>, capabilities: list<string>}
     */
    private function courseData(CourseDefinition $course): array
    {
        $key = self::cacheKey('data', $course);

        return $this->remember("course-data:{$key}", fn () => Cache::flexible($key, self::CACHE_TTL, function () use ($course) {
            $course->loadMissing('modules.questions');
            $validIds = ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions));
            $valid = array_flip($validIds->all());
            $hasQuestions = $course->contentQuestionIds()->isNotEmpty();

            return [
                'valid' => $validIds->values()->all(),
                'modules' => $course->modules->map(fn ($module) => [
                    'id' => (string) $module->id,
                    'code' => (string) $module->code,
                    'ids' => $module->questions->pluck('id')->unique()->filter(fn ($id) => isset($valid[$id]))->values()->all(),
                ])->values()->all(),
                'capabilities' => array_values(array_filter([
                    $hasQuestions ? 'smarttrainer' : null,
                    $hasQuestions ? 'favorites' : null,
                    $course->hasStartableExam() ? 'exam' : null,
                    VideoCourseLessons::ordered($course)->isNotEmpty() ? 'video' : null,
                    $course->navigationTasks()->exists() ? 'navigation' : null,
                    $course->praxisTasks()->exists() ? 'praxis' : null,
                    $course->files()->where('category', 'download')->where('published', true)->exists() ? 'downloads' : null,
                ])),
            ];
        }));
    }

    /** Freischaltzeitpunkt eines Moduls mit Drip-Regel (null = keine Regel). */
    public function dripUnlockAt(Tenant $tenant, User $user, CourseDefinition $course, string $capability, string $moduleCode): ?Carbon
    {
        $feature = self::RULE_FEATURES[$capability] ?? null;
        if ($feature === null || trim($moduleCode) === '') {
            return null;
        }

        $days = $this->rules($tenant)->get($course->id, collect())
            ->filter(fn (CourseAccessRule $rule) => $rule->feature_code === $feature && $rule->access_type === 'drip' && $rule->module_code === $moduleCode)
            ->max('limit_value');
        if ($days === null) {
            return null;
        }

        return $this->accessStartedAt($tenant, $user, $course, $capability)->copy()->addDays((int) $days);
    }

    public function isDripLocked(Tenant $tenant, User $user, CourseDefinition $course, string $capability, string $moduleCode): bool
    {
        return ($unlockAt = $this->dripUnlockAt($tenant, $user, $course, $capability, $moduleCode)) !== null
            && $unlockAt->isFuture();
    }

    /**
     * Freigegebene Fragen des Fragenkatalogs: alle gültigen Fragen bei vollem
     * Zugang, sonst die ersten N je Modul; Module mit aktiver Drip-Sperre
     * fallen heraus. Leer ohne Zugang.
     *
     * @return Collection<int, string>
     */
    public function accessibleQuestionIds(Tenant $tenant, User $user, CourseDefinition $course): Collection
    {
        return $this->remember("qids:{$tenant->id}:{$user->id}:{$course->id}", function () use ($tenant, $user, $course) {
            $scope = $this->featureScope($tenant, $user, $course, 'smarttrainer');
            $limit = $scope['full'] ? null : (int) ($scope['limit'] ?? 0);
            if ($limit !== null && $limit < 1) {
                return collect();
            }
            if ($limit === null && ! $this->hasDripRules($tenant, $course, 'smarttrainer')) {
                return $this->validQuestionIds($course);
            }

            $allowed = collect();
            foreach ($this->moduleQuestionIds($course) as $module) {
                if ($this->isDripLocked($tenant, $user, $course, 'smarttrainer', $module['code'])) {
                    continue;
                }
                $allowed = $allowed->merge($limit === null ? $module['ids'] : array_slice($module['ids'], 0, $limit));
            }

            return $allowed->unique()->values();
        });
    }

    /**
     * Funktionen, die der Kurs inhaltlich hat (nicht: die die Person nutzen darf).
     * 'funk' kommt mit den Funk-Endpunkten dazu; 'downloads' bei veröffentlichten
     * Kursdateien der Kategorie download.
     *
     * @return list<string>
     */
    public function capabilities(CourseDefinition $course): array
    {
        return $this->courseData($course)['capabilities'];
    }

    // --- intern ---------------------------------------------------------

    private function loggedInDefault(): bool
    {
        return config('mobile_api.default_access') === 'logged_in';
    }

    /** Favoriten und Erklärungen hängen am Fragenkatalog. */
    private function entitlementCapability(string $capability): string
    {
        return in_array($capability, ['favorites', 'explanations'], true) ? 'smarttrainer' : $capability;
    }

    private function hasCapabilityEntitlement(Tenant $tenant, User $user, CourseDefinition $course, string $capability): bool
    {
        return $this->activeEntitlements($tenant, $user)->contains(fn (Entitlement $e) => $e->course_id === $course->id
            && ($e->capabilities === null || in_array($capability, $e->capabilities, true)));
    }

    /** Freie Regeln und logged_in greifen nur für Kurse, die die Schule anbietet. */
    private function isOffered(Tenant $tenant, CourseDefinition $course): bool
    {
        if ($course->status !== 'published' || ! $course->site_enabled) {
            return false;
        }

        return ! app(DisabledCourses::class)->contains($tenant, $course->id);
    }

    private function hasDripRules(Tenant $tenant, CourseDefinition $course, string $capability): bool
    {
        $feature = self::RULE_FEATURES[$capability] ?? null;

        return $this->rules($tenant)->get($course->id, collect())
            ->contains(fn (CourseAccessRule $rule) => $rule->feature_code === $feature && $rule->access_type === 'drip');
    }

    /** Beginn des Zugangs für Drip: frühester Start eines passenden aktiven Zugangs, sonst Registrierung. */
    private function accessStartedAt(Tenant $tenant, User $user, CourseDefinition $course, string $capability): Carbon
    {
        $capability = $this->entitlementCapability($capability);
        $start = $this->entitlementRows($tenant, $user)
            ->filter(fn (Entitlement $e) => $e->course_id === $course->id && $e->status === 'active'
                && ($e->capabilities === null || in_array($capability, $e->capabilities, true)))
            ->pluck('valid_from')->filter()->min();

        return $start ? Carbon::parse($start) : ($user->created_at ? Carbon::parse($user->created_at) : now());
    }

    /** @return Collection<int, Entitlement> aktive Zugänge (veröffentlichte, angebotene Kurse) */
    private function activeEntitlements(Tenant $tenant, User $user): Collection
    {
        return $this->remember("active:{$tenant->id}:{$user->id}", fn () => $this->entitlements->activeEntitlements($tenant, $user));
    }

    /** @return Collection<int, Entitlement> alle Zugänge der Person in dieser Schule */
    private function entitlementRows(Tenant $tenant, User $user): Collection
    {
        return $this->remember("rows:{$tenant->id}:{$user->id}", fn () => Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get());
    }

    /** @return Collection<string, Collection<int, CourseAccessRule>> Regeln der Schule je Kurs (eine Abfrage) */
    private function rules(Tenant $tenant): Collection
    {
        return $this->remember("rules:{$tenant->id}", fn () => CourseAccessRule::where('tenant_id', $tenant->id)
            ->get(['course_id', 'feature_code', 'access_type', 'module_code', 'limit_value', 'valid_from', 'valid_until'])
            ->groupBy('course_id'));
    }

    /** @return Collection<string, string> */
    private function webshopUrls(Tenant $tenant): Collection
    {
        return $this->remember("webshop:{$tenant->id}", fn () => CourseWebshopLink::where('tenant_id', $tenant->id)->pluck('url', 'course_id'));
    }

    /**
     * Je Request zwischenspeichern. Laravel hält Controller (und damit diese
     * Instanz) über Requests hinweg an der Route fest -- daher wird der
     * Speicher beim ersten Zugriff in einem neuen Request verworfen
     * (sonst sähe ein zweiter Request alte Zugänge, siehe
     * ReviewRoundThirteenApiTest::test_failed_requests_are_not_replayed_by_idempotency).
     */
    private function remember(string $key, callable $resolve): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        if ($this->memoRequest?->get() !== $request) {
            $this->memo = [];
            $this->memoRequest = $request instanceof Request ? WeakReference::create($request) : null;
        }

        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $resolve();
        }

        return $this->memo[$key];
    }
}
