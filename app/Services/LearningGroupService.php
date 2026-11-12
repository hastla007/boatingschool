<?php

namespace App\Services;

use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;

class LearningGroupService
{
    public const INVITE_DAYS = 30;
    public const INVITES_PER_DAY = 20;

    public function __construct(private EntitlementService $entitlements) {}

    public function owned(User $user, string $id): LearningGroup
    {
        $group = LearningGroup::with('members.user')->whereKey($id)->where('owner_user_id', $user->id)->first();
        if (! $group) {
            throw new \App\Api\ApiProblem(404, 'NOT_FOUND', 'Gruppe nicht gefunden');
        }

        return $group;
    }

    public function active(LearningGroup $group): void
    {
        if (! $group->isActive()) {
            throw new \App\Api\ApiProblem(409, 'GROUP_NOT_ACTIVE', 'Gruppe nicht aktiv');
        }
    }

    public function invite(LearningGroup $group, User $owner, array $emails): array
    {
        $this->active($group);
        $userKey = 'learning-group-invite-user:'.$owner->id;
        if (RateLimiter::tooManyAttempts($userKey, 20)) {
            throw new \App\Api\ApiProblem(429, 'TOO_MANY_REQUESTS', 'Zu viele Einladungen', 'Bitte warte eine Stunde und versuche es erneut.');
        }
        RateLimiter::hit($userKey, 3600);
        $emails = array_values(array_unique(array_map(fn ($email) => mb_strtolower(trim((string) $email)), $emails)));
        $invited = 0;
        $skipped = [];
        foreach ($emails as $email) {
            $dailyKey = 'learning-group-invite-group:'.$group->id.':'.now()->toDateString();
            $dailyLimit = max(self::INVITES_PER_DAY, 2 * (int) $group->seats);
            if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $this->occupiedEmail($group, $email)
                || $this->freeSeats($group) <= 0 || RateLimiter::tooManyAttempts($dailyKey, $dailyLimit)) {
                $skipped[] = $email;
                continue;
            }
            RateLimiter::hit($dailyKey, 86400);
            $member = LearningGroupMember::create([
                'group_id' => $group->id, 'email' => $email, 'status' => 'invited',
                'invite_token' => 'i'.bin2hex(random_bytes(16)), 'invited_at' => now(),
            ]);
            // The invite code is also returned to the owner so an app can offer a universal link.
            try {
                \Illuminate\Support\Facades\Notification::route('mail', $email)->notify(new \App\Notifications\LearningGroupInvitation($group->name, $owner->name, (string) $member->invite_token));
            } catch (\Throwable $e) {
                report($e);
            }
            $invited++;
        }

        return ['invited' => $invited, 'skipped' => $skipped, 'group' => $this->payload($group->fresh('members.user'), $owner)];
    }

    public function removeMember(LearningGroup $group, string $memberId, string $reason): LearningGroup
    {
        $member = LearningGroupMember::where('group_id', $group->id)->whereKey($memberId)->where('status', '!=', 'removed')->first();
        if (! $member) {
            throw new \App\Api\ApiProblem(404, 'NOT_FOUND', 'Mitglied nicht gefunden');
        }
        DB::transaction(function () use ($member, $group, $reason) {
            $reference = 'group:'.$group->id.':'.$member->id;
            Entitlement::where('source_type', 'group')->where('source_reference', $reference)
                ->update(['status' => 'revoked', 'updated_at' => now()]);
            $member->update(['status' => 'removed', 'invite_token' => null, 'email' => null]);
        });

        return $group->fresh('members.user');
    }

    public function regenerate(LearningGroup $group): LearningGroup
    {
        $this->active($group);
        $group->update(['join_code' => 'g'.bin2hex(random_bytes(16))]);

        return $group->fresh('members.user');
    }

    public function preview(string $token, User $user): array
    {
        [$group, $member] = $this->resolve($token);
        if (! $group || ! $group->isActive()) {
            throw new \App\Api\ApiProblem(422, 'COUPON_INVALID', 'Dieser Einladungslink ist ungültig oder abgelaufen.');
        }
        $already = LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->where('status', 'active')->exists();
        $free = $this->freeSeats($group) + ($member ? 1 : 0);
        $owner = $group->owner;

        return [
            'name' => $group->name, 'owner_name' => $owner?->name, 'personal' => $member !== null,
            'status' => $group->status, 'valid_until' => $group->valid_until?->toIso8601String(),
            'free_seats' => $free, 'already_member' => $already,
            'joinable' => $already || ($free > 0 && $this->supportsScopes($group)),
            'scopes' => $this->scopes($group),
        ];
    }

    public function join(string $token, Tenant $tenant, User $user): array
    {
        return DB::transaction(function () use ($token, $tenant, $user) {
            [$group, $invite] = $this->resolve($token, lock: true);
            if (! $group || ! $group->isActive()) {
                throw new RuntimeException('GROUP_INVALID');
            }
            if ($invite && mb_strtolower((string) $invite->email) !== mb_strtolower((string) $user->email)) {
                throw new RuntimeException('GROUP_INVALID');
            }
            $existing = LearningGroupMember::where('group_id', $group->id)->where('user_id', $user->id)->where('status', 'active')->first();
            if ($existing) {
                return ['group' => $group, 'member' => $existing, 'already' => true];
            }
            if (! $invite && $this->freeSeats($group) <= 0) {
                throw new RuntimeException('GROUP_INVALID');
            }
            try {
                $courseGrants = $this->courseGrants($group);
            } catch (RuntimeException $e) {
                throw new RuntimeException('GROUP_SCOPE_UNSUPPORTED', previous: $e);
            }
            foreach ($courseGrants as $grant) {
                if (! $this->entitlements->isOfferable($tenant, $grant['course'])) {
                    throw new RuntimeException('GROUP_INVALID');
                }
            }

            $member = $invite;
            if ($member) {
                $member->update(['user_id' => $user->id, 'status' => 'active', 'invite_token' => null, 'joined_at' => now()]);
            } else {
                $member = LearningGroupMember::create(['group_id' => $group->id, 'user_id' => $user->id, 'status' => 'active', 'joined_at' => now()]);
            }

            foreach ($courseGrants as $grant) {
                Entitlement::firstOrCreate([
                    'tenant_id' => $tenant->id, 'user_id' => $user->id, 'course_id' => $grant['course']->id,
                    'source_type' => 'group', 'source_reference' => 'group:'.$group->id.':'.$member->id,
                ], [
                    'status' => 'active', 'valid_from' => now(), 'valid_until' => $group->valid_until,
                    'capabilities' => $grant['capabilities'],
                ]);
            }

            return ['group' => $group, 'member' => $member, 'already' => false];
        });
    }

    public function payload(LearningGroup $group, User $viewer): array
    {
        $members = [];
        $used = 0;
        foreach ($group->members as $member) {
            if ($member->status === 'removed') {
                continue;
            }
            $expired = $member->status === 'invited' && $member->invited_at && $member->invited_at->lt(now()->subDays(self::INVITE_DAYS));
            $status = $expired ? 'expired' : $member->status;
            if (in_array($status, ['active','invited'], true)) {
                $used++;
            }
            $members[] = [
                'id' => $member->id, 'user_id' => $member->user_id,
                'name' => $member->user?->name, 'email' => $member->email,
                'status' => $status, 'is_me' => $member->user_id === $viewer->id,
                'joined_at' => $member->joined_at?->toIso8601String(), 'invited_at' => $member->invited_at?->toIso8601String(),
                'invite_expires_at' => $expired || $member->status === 'invited' ? $member->invited_at?->copy()->addDays(self::INVITE_DAYS)->toIso8601String() : null,
                'removable' => in_array($status, ['active','invited'], true),
            ];
        }
        $active = $group->isActive();
        $free = max(0, (int) $group->seats - $used);

        return [
            'id' => $group->id, 'name' => $group->name, 'status' => $group->status,
            'valid_until' => $group->valid_until?->toIso8601String(),
            'seats' => ['total' => (int) $group->seats, 'used' => $used, 'free' => $free],
            'scopes' => $this->scopes($group),
            'join_code' => $active ? $group->join_code : null,
            'join_url' => $active ? rtrim(config('app.url'), '/').'/gruppe/'.$group->join_code.'/' : null,
            'invites_remaining_today' => $active ? RateLimiter::remaining('learning-group-invite-group:'.$group->id.':'.now()->toDateString(), max(self::INVITES_PER_DAY, 2 * (int) $group->seats)) : 0,
            'invite_valid_days' => self::INVITE_DAYS,
            'members' => $members,
        ];
    }

    private function resolve(string $token, bool $lock = false): array
    {
        if (preg_match('/^[gi][0-9a-f]{32}$/i', $token) !== 1) {
            return [null, null];
        }
        if (str_starts_with(strtolower($token), 'i')) {
            $query = LearningGroupMember::where('invite_token', strtolower($token))->where('status', 'invited');
            $member = $lock ? $query->lockForUpdate()->first() : $query->first();
            $group = $member?->group()->first();
            if ($member?->invited_at && $member->invited_at->lt(now()->subDays(self::INVITE_DAYS))) {
                return [null, null];
            }

            return [$group, $member];
        }
        $query = LearningGroup::where('join_code', strtolower($token));
        $group = $lock ? $query->lockForUpdate()->first() : $query->first();

        return [$group, null];
    }

    private function occupiedEmail(LearningGroup $group, string $email): bool
    {
        return LearningGroupMember::where('group_id', $group->id)->whereRaw('lower(email) = ?', [$email])->whereIn('status', ['active','invited'])->exists();
    }

    private function freeSeats(LearningGroup $group): int
    {
        $used = LearningGroupMember::where('group_id', $group->id)->whereIn('status', ['active','invited'])
            ->where(fn ($q) => $q->where('status', 'active')->orWhereNull('invited_at')->orWhere('invited_at', '>=', now()->subDays(self::INVITE_DAYS)))
            ->count();

        return max(0, (int) $group->seats - $used);
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

    /**
     * Resolve WP feature scopes to the native API capabilities. A null capability list
     * means full-course access; a non-null list can only open the named API areas.
     *
     * @return array<string, array{course:CourseDefinition,capabilities:?array}>
     */
    private function courseGrants(LearningGroup $group): array
    {
        if (empty($group->scopes)) {
            throw new RuntimeException('GROUP_SCOPE_UNSUPPORTED');
        }
        $grants = [];
        foreach ($group->scopes ?? [] as $scope) {
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

    /** @return ?array<string> null grants the entire course */
    private function scopeCapabilities(array $scope): ?array
    {
        $feature = strtolower((string) ($scope['feature'] ?? 'course'));
        if (in_array($feature, ['course', 'full', 'all'], true)) {
            return null;
        }

        $capability = strtolower((string) ($scope['capability'] ?? ''));
        $allowed = ['smarttrainer', 'favorites', 'exam', 'video', 'navigation', 'praxis', 'funk'];
        if (in_array($capability, $allowed, true)) {
            return [$capability];
        }

        $mapping = [
            'question_bank' => ['smarttrainer', 'favorites'],
            'exam_simulation' => ['exam'],
            'video_course' => ['video'],
            'navigation_trainer' => ['navigation'],
            'praxis_trainer' => ['praxis'],
            'funk_training' => ['funk'],
            'radio_training' => ['funk'],
        ];
        if (isset($mapping[$feature])) {
            return $mapping[$feature];
        }

        throw new RuntimeException('GROUP_SCOPE_UNSUPPORTED');
    }

    private function scopes(LearningGroup $group): array
    {
        return collect($group->scopes ?? [])->map(function ($scope) {
            $code = $scope['course_code'] ?? $scope['course'] ?? '';
            $course = CourseDefinition::withoutGlobalScopes()->where('code', $code)->first();
            $feature = (string) ($scope['feature'] ?? 'course');
            try {
                $capabilities = $this->scopeCapabilities($scope);
            } catch (RuntimeException) {
                $capabilities = [];
            }
            $capability = $scope['capability'] ?? ($capabilities[0] ?? null);
            $labels = [
                'course' => 'Vollzugang', 'full' => 'Vollzugang', 'all' => 'Vollzugang',
                'question_bank' => 'Fragenbank', 'exam_simulation' => 'Prüfungssimulation',
                'video_course' => 'Videokurs', 'navigation_trainer' => 'Navigationstrainer',
                'praxis_trainer' => 'Praxistrainer', 'funk_training' => 'Funktraining',
                'radio_training' => 'Funktraining',
            ];

            return [
                'course_id' => $course?->id, 'course_code' => $code,
                'course_name' => $course?->name ?? $code, 'feature' => $feature,
                'capability' => $capability,
                'feature_label' => $labels[$feature] ?? $feature,
            ];
        })->all();
    }
}
