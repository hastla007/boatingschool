<?php

namespace App\Services;

use App\Api\ApiProblem;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LearningGroupInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

/**
 * Lerngruppen (Gruppenlizenzen der WP-App). Regeln:
 * - Eine Gruppe gehört genau einer Schule (RLS); alles hier läuft im
 *   Mandantenkontext der Mobile-API. Angelegt wird sie vom Betreiber
 *   (learning-groups:create), verwaltet vom eingetragenen Verwalter.
 * - Nur der Verwalter sieht die Gruppe, lädt ein, entfernt und erneuert den
 *   Beitrittscode. Beitreten können nur Teilnehmer derselben Schule.
 * - Plätze: aktive Mitglieder + offene, nicht abgelaufene Einladungen.
 *   Jede Platzänderung sperrt die Gruppenzeile (FOR UPDATE), parallele
 *   Einladungen oder Beitritte können die Platzzahl nicht überziehen.
 * - Der Beitritt legt je Kurs ein Entitlement mit source_type "group" an
 *   (capabilities = null: ganzer Kurs, sonst nur die genannten Funktionen).
 *   Entfernen beendet genau diese Entitlements (status expired, nicht
 *   revoked -- QA-L-2), eigene Zugänge bleiben.
 * - Antworten auf Einladungen verraten nie, ob es zu einer Adresse ein
 *   Konto gibt.
 */
class LearningGroupService
{
    public const INVITE_DAYS = 30;

    public const INVITES_PER_DAY = 20;

    public const INVITE_REQUESTS_PER_HOUR = 20;

    /** Funktionen, die eine Gruppe freischalten kann (entitlement.capabilities). */
    public const CAPABILITIES = ['smarttrainer', 'favorites', 'exam', 'video', 'navigation', 'praxis', 'funk'];

    /** WP-Funktionsnamen → Capabilities. */
    private const FEATURES = [
        'question_bank' => ['smarttrainer', 'favorites'],
        'exam_simulation' => ['exam'],
        'video_course' => ['video'],
        'navigation_trainer' => ['navigation'],
        'praxis_trainer' => ['praxis'],
        'funk_training' => ['funk'],
        'radio_training' => ['funk'],
    ];

    private const FEATURE_LABELS = [
        'course' => 'Vollzugang', 'full' => 'Vollzugang', 'all' => 'Vollzugang',
        'question_bank' => 'Fragenbank', 'exam_simulation' => 'Prüfungssimulation',
        'video_course' => 'Videokurs', 'navigation_trainer' => 'Navigationstrainer',
        'praxis_trainer' => 'Praxistrainer', 'funk_training' => 'Funktraining',
        'radio_training' => 'Funktraining',
    ];

    public function __construct(private EntitlementService $entitlements) {}

    /** Gruppe des Verwalters in der aktuellen Schule, sonst 404 (auch für fremde Gruppen). */
    public function owned(User $user, string $id): LearningGroup
    {
        $group = LearningGroup::whereKey($id)->where('owner_user_id', $user->id)->first();
        if (! $group) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Gruppe nicht gefunden');
        }

        return $group;
    }

    /**
     * @param  list<string>  $emails
     * @return array{invited:int, skipped:list<string>, group:array}
     */
    public function invite(LearningGroup $group, User $owner, array $emails): array
    {
        $userKey = 'learning-group-invite-user:'.$owner->id;
        if (RateLimiter::tooManyAttempts($userKey, self::INVITE_REQUESTS_PER_HOUR)) {
            throw new ApiProblem(429, 'TOO_MANY_REQUESTS', 'Zu viele Einladungen', 'Bitte warte eine Stunde und versuche es erneut.');
        }
        RateLimiter::hit($userKey, 3600);

        $emails = array_values(array_unique(array_map(fn ($email) => mb_strtolower(trim((string) $email)), $emails)));
        $tenant = $group->tenant_id;

        [$invites, $skipped] = DB::transaction(function () use ($group, $emails, $tenant) {
            $locked = $this->lock($group);
            $this->ensureActive($locked);
            $this->expireStaleInvites($locked);
            $free = $this->freeSeats($locked);
            $dailyKey = $this->dailyKey($locked);
            $dailyLimit = $this->dailyLimit($locked);

            $invites = [];
            $skipped = [];
            foreach ($emails as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 320 || $free <= 0
                    || $this->occupiedEmail($locked, $email) || RateLimiter::tooManyAttempts($dailyKey, $dailyLimit)) {
                    $skipped[] = $email;

                    continue;
                }
                RateLimiter::hit($dailyKey, 86400);
                $invites[] = LearningGroupMember::create([
                    'tenant_id' => $tenant, 'group_id' => $locked->id, 'email' => $email, 'status' => 'invited',
                    'invite_token' => 'i'.bin2hex(random_bytes(16)), 'invited_at' => now(),
                ]);
                $free--;
            }

            return [$invites, $skipped];
        });

        // Erst nach dem Commit verschicken: an jede gültige Adresse gleich,
        // ob es dazu ein Konto gibt (keine Auskunft darüber an den Verwalter).
        // Audit ARC-2: über die Queue (im Namen der Schule der Gruppe).
        $school = Tenant::find($tenant)?->name ?? '';
        foreach ($invites as $member) {
            try {
                Notification::route('mail', $member->email)->notify(new LearningGroupInvitation(
                    $group->name, $owner->name, $school, (string) $member->invite_token, self::INVITE_DAYS, $tenant,
                ));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return ['invited' => count($invites), 'skipped' => $skipped, 'group' => $this->payload($group->fresh(), $owner)];
    }

    /**
     * Mitglied oder offene Einladung entfernen: Platz wird frei, die über
     * die Gruppe erteilten Zugänge werden widerrufen. Andere Zugänge der
     * Person (Kauf, Code, Schule) bleiben unberührt.
     */
    public function removeMember(LearningGroup $group, string $memberId): LearningGroup
    {
        DB::transaction(function () use ($group, $memberId) {
            $this->lock($group);
            $member = LearningGroupMember::where('group_id', $group->id)->whereKey($memberId)
                ->whereIn('status', ['invited', 'active'])->lockForUpdate()->first();
            if (! $member) {
                throw new ApiProblem(404, 'NOT_FOUND', 'Mitglied nicht gefunden');
            }
            $this->revokeEntitlements($group, $member);
            $member->update(['status' => 'removed', 'invite_token' => null, 'email' => null]);
        });

        return $group->fresh();
    }

    public function regenerate(LearningGroup $group): LearningGroup
    {
        DB::transaction(function () use ($group) {
            $locked = $this->lock($group);
            $this->ensureActive($locked);
            $locked->update(['join_code' => self::newJoinCode()]);
        });

        return $group->fresh();
    }

    public function preview(string $token, User $user): array
    {
        [$group, $invite] = $this->resolve($token, $user);
        if (! $group || ! $group->isActive()) {
            throw new ApiProblem(422, 'COUPON_INVALID', 'Code ungültig', 'Dieser Einladungslink ist ungültig oder abgelaufen.');
        }
        $already = LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->where('status', 'active')->exists();
        $removed = ! $invite && ! $already && $this->wasRemoved($group, $user);
        $free = $this->freeSeats($group) + ($invite ? 1 : 0);

        return [
            'name' => $group->name, 'owner_name' => $group->owner?->name, 'personal' => $invite !== null,
            'status' => $group->status, 'valid_until' => $group->valid_until?->toIso8601String(),
            'free_seats' => $free, 'already_member' => $already,
            'joinable' => $already || (! $removed && $free > 0 && $this->supportsScopes($group)),
            'scopes' => $this->scopes($group),
        ];
    }

    /**
     * Beitritt über Beitrittscode (g…) oder persönliche Einladung (i…).
     * Fehler als RuntimeException: GROUP_INVALID (einheitlich, kein
     * Ausprobieren) oder GROUP_SCOPE_UNSUPPORTED.
     *
     * @return array{group:LearningGroup, member:LearningGroupMember, already:bool}
     */
    public function join(string $token, Tenant $tenant, User $user): array
    {
        return DB::transaction(function () use ($token, $tenant, $user) {
            [$group, $invite] = $this->resolve($token, $user, lock: true);
            if (! $group || ! $group->isActive() || $group->tenant_id !== $tenant->id) {
                throw new RuntimeException('GROUP_INVALID');
            }

            $existing = LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->where('status', 'active')->first();
            if ($existing) {
                // Schon Mitglied (z. B. über den Beitrittscode): die
                // zusätzliche Einladung gibt ihren Platz wieder frei.
                $invite?->update(['status' => 'expired', 'invite_token' => null, 'email' => null]);

                return ['group' => $group, 'member' => $existing, 'already' => true];
            }
            if (! $invite && ($this->wasRemoved($group, $user) || $this->freeSeats($group) <= 0)) {
                throw new RuntimeException('GROUP_INVALID');
            }
            try {
                $grants = $this->courseGrants($group);
            } catch (RuntimeException $e) {
                throw new RuntimeException('GROUP_SCOPE_UNSUPPORTED', previous: $e);
            }
            foreach ($grants as $grant) {
                if (! $this->entitlements->isOfferable($tenant, $grant['course'])) {
                    throw new RuntimeException('GROUP_INVALID');
                }
            }

            if ($invite) {
                $invite->update(['user_id' => $user->id, 'status' => 'active', 'invite_token' => null, 'joined_at' => now()]);
                $member = $invite;
            } else {
                $member = LearningGroupMember::create([
                    'tenant_id' => $tenant->id, 'group_id' => $group->id, 'user_id' => $user->id,
                    'status' => 'active', 'joined_at' => now(),
                ]);
            }

            foreach ($grants as $grant) {
                Entitlement::create([
                    'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $grant['course']->id,
                    'source_type' => 'group', 'source_reference' => self::reference($group, $member),
                    'status' => 'active', 'valid_from' => now(), 'valid_until' => $group->valid_until,
                    'capabilities' => $grant['capabilities'],
                ]);
            }

            return ['group' => $group, 'member' => $member, 'already' => false];
        });
    }

    /** Gruppe ganz beenden (Betreiber): alle Gruppenzugänge enden, offene Einladungen verfallen. */
    public function revokeGroup(LearningGroup $group): void
    {
        DB::transaction(function () use ($group) {
            $locked = $this->lock($group);
            Entitlement::withoutGlobalScopes()->where('tenant_id', $locked->tenant_id)->where('source_type', 'group')
                ->where('source_reference', 'like', 'group:'.$locked->id.':%')->where('status', 'active')
                ->update(self::endedAttributes());
            LearningGroupMember::withoutGlobalScopes()->where('group_id', $locked->id)->where('status', 'invited')
                ->update(['status' => 'expired', 'invite_token' => null, 'email' => null, 'updated_at' => now()]);
            $locked->update(['status' => 'revoked']);
        });
    }

    public function payload(LearningGroup $group, User $viewer): array
    {
        $group->loadMissing('members.user');
        $members = [];
        $used = 0;
        foreach ($group->members->sortBy('created_at') as $member) {
            if (! in_array($member->status, ['active', 'invited'], true)) {
                continue;
            }
            $status = $this->isStale($member) ? 'expired' : $member->status;
            if ($status !== 'expired') {
                $used++;
            }
            $members[] = [
                'id' => $member->id, 'user_id' => $member->user_id,
                'name' => $member->user?->name, 'email' => $member->email,
                'status' => $status, 'is_me' => $member->user_id === $viewer->id,
                'joined_at' => $member->joined_at?->toIso8601String(), 'invited_at' => $member->invited_at?->toIso8601String(),
                'invite_expires_at' => $member->status === 'invited' ? $member->invited_at?->copy()->addDays(self::INVITE_DAYS)->toIso8601String() : null,
                'removable' => true,
            ];
        }
        $active = $group->isActive();

        return [
            'id' => $group->id, 'name' => $group->name, 'status' => $group->status, 'active' => $active,
            'valid_until' => $group->valid_until?->toIso8601String(),
            'seats' => ['total' => (int) $group->seats, 'used' => $used, 'free' => max(0, (int) $group->seats - $used)],
            'scopes' => $this->scopes($group),
            'join_code' => $active ? $group->join_code : null,
            'join_url' => $active ? rtrim((string) config('app.url'), '/').'/gruppe/'.$group->join_code.'/' : null,
            'invites_remaining_today' => $active ? RateLimiter::remaining($this->dailyKey($group), $this->dailyLimit($group)) : 0,
            'invite_valid_days' => self::INVITE_DAYS,
            'members' => $members,
        ];
    }

    public static function newJoinCode(): string
    {
        return 'g'.bin2hex(random_bytes(16));
    }

    /**
     * Scopes prüfen (beim Anlegen). Ein Scope: {course_code, feature?, capability?}.
     *
     * @param  list<array<string, mixed>>  $scopes
     */
    public function validateScopes(array $scopes): void
    {
        $this->grantsFor($scopes);
    }

    private static function reference(LearningGroup $group, LearningGroupMember $member): string
    {
        return 'group:'.$group->id.':'.$member->id;
    }

    private function revokeEntitlements(LearningGroup $group, LearningGroupMember $member): void
    {
        Entitlement::where('tenant_id', $group->tenant_id)->where('source_type', 'group')
            ->where('source_reference', self::reference($group, $member))->where('status', 'active')
            ->update(self::endedAttributes());
    }

    /**
     * Ende eines Gruppenzugangs: abgelaufen zum jetzigen Zeitpunkt, nicht
     * "revoked" -- das ist die bewusste Sperre der Schule (Betrug/Rück-
     * buchung) und hätte jeden späteren eigenen Code bzw. das Freischalten
     * blockiert (QA-L-2).
     */
    private static function endedAttributes(): array
    {
        $now = now()->toIso8601String();

        // LEAST ignoriert NULL: unbefristete Gruppenzugänge enden jetzt; die
        // Untergrenze hält entitlement_valid_range_check (valid_until > valid_from).
        return [
            'status' => 'expired',
            'valid_until' => DB::raw("LEAST(valid_until, GREATEST('{$now}'::timestamptz, valid_from + interval '1 second'))"),
            'updated_at' => now(),
        ];
    }

    private function lock(LearningGroup $group): LearningGroup
    {
        $locked = LearningGroup::whereKey($group->id)->lockForUpdate()->first();
        if (! $locked) {
            throw new ApiProblem(404, 'NOT_FOUND', 'Gruppe nicht gefunden');
        }

        return $locked;
    }

    private function ensureActive(LearningGroup $group): void
    {
        if (! $group->isActive()) {
            throw new ApiProblem(409, 'GROUP_NOT_ACTIVE', 'Gruppe nicht aktiv', 'Diese Gruppenlizenz ist abgelaufen oder gesperrt.');
        }
    }

    /**
     * Token auflösen. Eine persönliche Einladung gilt nur für die
     * eingeladene Adresse -- für alle anderen sieht sie aus wie ein
     * unbekannter Code. Mit $lock wird zuerst die Gruppe, dann die
     * Einladung gesperrt (immer dieselbe Reihenfolge wie bei Einladen und
     * Entfernen).
     *
     * @return array{0:?LearningGroup, 1:?LearningGroupMember}
     */
    private function resolve(string $token, User $user, bool $lock = false): array
    {
        $token = strtolower($token);
        if (preg_match('/^[gi][0-9a-f]{32}$/', $token) !== 1) {
            return [null, null];
        }
        if ($token[0] === 'g') {
            $query = LearningGroup::where('join_code', $token);

            return [$lock ? $query->lockForUpdate()->first() : $query->first(), null];
        }

        $invite = LearningGroupMember::where('invite_token', $token)->where('status', 'invited')->first();
        if (! $invite || $this->isStale($invite) || mb_strtolower((string) $invite->email) !== mb_strtolower((string) $user->email)) {
            return [null, null];
        }
        $group = LearningGroup::whereKey($invite->group_id);
        $group = $lock ? $group->lockForUpdate()->first() : $group->first();
        if ($lock && $group) {
            $invite = LearningGroupMember::whereKey($invite->id)->where('status', 'invited')->lockForUpdate()->first();
            if (! $invite) {
                return [null, null];
            }
        }

        return [$group, $invite];
    }

    private function isStale(LearningGroupMember $member): bool
    {
        return $member->status === 'invited' && $member->invited_at !== null
            && $member->invited_at->lt(now()->subDays(self::INVITE_DAYS));
    }

    /** Abgelaufene Einladungen geben Platz und Adresse frei. */
    private function expireStaleInvites(LearningGroup $group): void
    {
        LearningGroupMember::where('group_id', $group->id)->where('status', 'invited')
            ->where('invited_at', '<', now()->subDays(self::INVITE_DAYS))
            ->update(['status' => 'expired', 'invite_token' => null, 'email' => null, 'updated_at' => now()]);
    }

    private function wasRemoved(LearningGroup $group, User $user): bool
    {
        return LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->where('status', 'removed')->exists();
    }

    private function occupiedEmail(LearningGroup $group, string $email): bool
    {
        return LearningGroupMember::where('group_id', $group->id)->whereRaw('lower(email) = ?', [$email])
            ->whereIn('status', ['active', 'invited'])->exists();
    }

    private function freeSeats(LearningGroup $group): int
    {
        $used = LearningGroupMember::where('group_id', $group->id)
            ->where(fn ($q) => $q->where('status', 'active')
                ->orWhere(fn ($i) => $i->where('status', 'invited')->where('invited_at', '>=', now()->subDays(self::INVITE_DAYS))))
            ->count();

        return max(0, (int) $group->seats - $used);
    }

    private function dailyKey(LearningGroup $group): string
    {
        return 'learning-group-invite-group:'.$group->id.':'.now()->toDateString();
    }

    private function dailyLimit(LearningGroup $group): int
    {
        return max(self::INVITES_PER_DAY, 2 * (int) $group->seats);
    }

    private function supportsScopes(LearningGroup $group): bool
    {
        try {
            $this->courseGrants($group);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @return array<string, array{course:CourseDefinition, capabilities:?list<string>}> */
    private function courseGrants(LearningGroup $group): array
    {
        return $this->grantsFor($group->scopes ?? []);
    }

    /**
     * WP-Funktionsumfang → Kurs + Capabilities. null = ganzer Kurs; mehrere
     * Scopes für denselben Kurs werden vereinigt.
     *
     * @return array<string, array{course:CourseDefinition, capabilities:?list<string>}>
     */
    private function grantsFor(array $scopes): array
    {
        if ($scopes === []) {
            throw new RuntimeException('GROUP_SCOPE_UNSUPPORTED');
        }
        $grants = [];
        foreach ($scopes as $scope) {
            $code = (string) ($scope['course_code'] ?? $scope['course'] ?? '');
            $course = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->where('code', $code)->first();
            if (! $course) {
                throw new RuntimeException('GROUP_COURSE_UNAVAILABLE');
            }
            $mapped = $this->scopeCapabilities($scope);
            if (! array_key_exists($code, $grants)) {
                $grants[$code] = ['course' => $course, 'capabilities' => $mapped];
            } elseif ($grants[$code]['capabilities'] !== null && $mapped !== null) {
                $grants[$code]['capabilities'] = array_values(array_unique(array_merge($grants[$code]['capabilities'], $mapped)));
            } else {
                $grants[$code]['capabilities'] = null;
            }
        }

        return $grants;
    }

    /** @return ?list<string> null = ganzer Kurs */
    private function scopeCapabilities(array $scope): ?array
    {
        $feature = strtolower((string) ($scope['feature'] ?? 'course'));
        if (in_array($feature, ['course', 'full', 'all'], true)) {
            return null;
        }
        $capability = strtolower((string) ($scope['capability'] ?? ''));
        if (in_array($capability, self::CAPABILITIES, true)) {
            return [$capability];
        }
        if (isset(self::FEATURES[$feature])) {
            return self::FEATURES[$feature];
        }

        throw new RuntimeException('GROUP_SCOPE_UNSUPPORTED');
    }

    private function scopes(LearningGroup $group): array
    {
        $codes = collect($group->scopes ?? [])->map(fn ($scope) => $scope['course_code'] ?? $scope['course'] ?? '')->unique()->values();
        $courses = CourseDefinition::withoutGlobalScopes()->whereNull('tenant_id')->whereIn('code', $codes)->get()->keyBy('code');

        return collect($group->scopes ?? [])->map(function ($scope) use ($courses) {
            $code = (string) ($scope['course_code'] ?? $scope['course'] ?? '');
            $course = $courses->get($code);
            $feature = (string) ($scope['feature'] ?? 'course');
            try {
                $capabilities = $this->scopeCapabilities($scope);
            } catch (RuntimeException) {
                $capabilities = [];
            }

            return [
                'course_id' => $course?->id, 'course_code' => $code,
                'course_name' => $course?->name ?? $code, 'feature' => $feature,
                'capability' => $scope['capability'] ?? ($capabilities[0] ?? null),
                'capabilities' => $capabilities,
                'feature_label' => self::FEATURE_LABELS[$feature] ?? $feature,
            ];
        })->all();
    }
}
