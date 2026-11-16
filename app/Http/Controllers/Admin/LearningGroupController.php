<?php

namespace App\Http\Controllers\Admin;

use App\Api\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LearningGroup;
use App\Models\LearningGroupMember;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\LearningGroupService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Lerngruppen (Gruppenlizenzen) im Admin-Bereich, nur Inhaber/Admins
 * (routes/web.php): Liste im Tab "Lerngruppen" (AdminDashboardController),
 * hier Anlegen (eigene Seite mit Verwalterauswahl), Detailseite mit
 * Bearbeiten und Mitgliedern, Beenden und Mitglied entfernen. Die Regeln liegen in LearningGroupService (dieselben
 * wie beim Betreiber-Befehl); jede Änderung wird protokolliert. Läuft auf
 * der App-Verbindung mit RLS -- Gruppen einfügen darf die App-Rolle seit
 * 2024_02_01_000800, nur in der eigenen Schule. Plätze werden beim Beitritt
 * mit Kurscodes der Schule abgerechnet (nicht für Kurse mit eigenem
 * Vollzugang); Anlegen und Detailseite zeigen je Kurs die freien Codes und
 * warnen, wenn sie für die offenen Plätze nicht reichen (Momentaufnahme).
 */
class LearningGroupController extends Controller
{
    private const MESSAGES = [
        'name.required' => 'Bitte einen Namen angeben.',
        'name.max' => 'Der Name darf höchstens :max Zeichen lang sein.',
        'owner_user_id.required' => 'Bitte einen Verwalter wählen.',
        'owner_user_id.uuid' => 'Der Verwalter muss aktiver Teilnehmer dieser Schule sein.',
        'seats.required' => 'Bitte die Platzzahl angeben.',
        'seats.integer' => 'Die Platzzahl muss eine ganze Zahl sein.',
        'seats.min' => 'Die Platzzahl muss zwischen 1 und '.LearningGroupService::MAX_SEATS.' liegen.',
        'seats.max' => 'Die Platzzahl muss zwischen 1 und '.LearningGroupService::MAX_SEATS.' liegen.',
        'courses.required' => 'Bitte mindestens einen Kurs wählen.',
        'courses.array' => 'Bitte mindestens einen Kurs wählen.',
        'courses.*.in' => 'Bitte für jeden Kurs einen gültigen Funktionsumfang wählen.',
        'valid_until.date_format' => 'Bitte ein gültiges Datum angeben.',
        'valid_until.after' => 'Das Ende der Lerngruppe muss in der Zukunft liegen.',
    ];

    public function create(TenantContext $tenantContext, LearningGroupService $groups): View
    {
        $courses = $groups->offeredCourses($tenantContext->tenant());

        return view('admin.learning-groups.create', [
            'courses' => $courses,
            // Jeder Beitritt verbraucht je Kurs einen freien Kurscode der Schule.
            'freeCodes' => $groups->freeCourseCodes($tenantContext->id(), $courses->pluck('id')),
            'managers' => $this->eligibleManagers($tenantContext->id()),
        ]);
    }

    public function store(Request $request, TenantContext $tenantContext, LearningGroupService $groups): RedirectResponse
    {
        $tenant = $tenantContext->tenant();
        $offered = $groups->offeredCourses($tenant)->pluck('code')->all();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.LearningGroupService::MAX_NAME_LENGTH],
            'owner_user_id' => ['required', 'uuid'],
            'seats' => ['required', 'integer', 'min:1', 'max:'.LearningGroupService::MAX_SEATS],
            'courses' => ['required', 'array'],
            'courses.*' => ['nullable', 'string', Rule::in(array_keys(LearningGroupService::FEATURE_OPTIONS))],
            'valid_until' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ], self::MESSAGES);

        // courses[KURSCODE] = Funktion, leer = Kurs nicht enthalten.
        $scopes = collect($validated['courses'])->filter()
            ->map(fn (string $feature, string|int $code) => ['course_code' => (string) $code, 'feature' => $feature])->values()->all();
        if ($scopes === []) {
            throw ValidationException::withMessages(['courses' => 'Bitte mindestens einen Kurs wählen.']);
        }
        if (array_diff(array_column($scopes, 'course_code'), $offered) !== []) {
            throw ValidationException::withMessages(['courses' => 'Bitte nur Kurse wählen, die Ihre Bootsschule anbietet.']);
        }
        $owner = $this->activeAccount($validated['owner_user_id']);

        // Verwalter, Name, Plätze, Funktionen, Ende: dieselben Regeln wie app:learning-group-create.
        $group = $groups->create($tenant, $owner, $validated['name'], (int) $validated['seats'], $scopes,
            LearningGroupService::validUntilFromDate($validated['valid_until'] ?? null), 'school_admin');

        AuditLog::record($tenant->id, $request->user()->id, 'learning_group.create', 'learning_group', $group->id, null, [
            'name' => $group->name, 'owner_user_id' => $group->owner_user_id, 'seats' => $group->seats,
            'scopes' => $group->scopes, 'valid_until' => $group->valid_until?->toIso8601String(),
        ]);

        return redirect()->route('admin.learning-groups.show', $group)->with('status', 'Lerngruppe angelegt.');
    }

    public function show(TenantContext $tenantContext, LearningGroupService $groups, LearningGroup $learningGroup, Request $request): View
    {
        $this->ensureOwnSchool($tenantContext, $learningGroup);
        $payload = $groups->payload($learningGroup, $request->user(), exactCodeCounts: true);
        $users = $learningGroup->members->keyBy('id');
        // Mitglieder per Beitrittscode haben keine Adresse am Platz: die des Kontos zeigen.
        $members = collect($payload['members'])->map(fn (array $member) => $member + [
            'account_email' => $users->get($member['id'])?->user?->email,
        ]);

        return view('admin.learning-groups.show', [
            'group' => $learningGroup->loadMissing('owner'),
            'payload' => $payload,
            'members' => $members,
            'managers' => $this->eligibleManagers($tenantContext->id()),
            'statusLabel' => self::statusLabel($learningGroup),
        ]);
    }

    public function update(Request $request, TenantContext $tenantContext, LearningGroupService $groups, LearningGroup $learningGroup): RedirectResponse
    {
        $this->ensureOwnSchool($tenantContext, $learningGroup);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.LearningGroupService::MAX_NAME_LENGTH],
            'owner_user_id' => ['required', 'uuid'],
            'seats' => ['required', 'integer', 'min:1', 'max:'.LearningGroupService::MAX_SEATS],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
        ], self::MESSAGES);

        if ($validated['owner_user_id'] !== $learningGroup->owner_user_id) {
            $this->activeAccount($validated['owner_user_id']);
        }

        $changes = $groups->update($learningGroup, [
            'name' => $validated['name'],
            'seats' => (int) $validated['seats'],
            'valid_until' => LearningGroupService::validUntilFromDate($validated['valid_until'] ?? null),
            'owner_user_id' => $validated['owner_user_id'],
        ]);
        if ($changes['after'] === []) {
            return back()->with('status', 'Keine Änderungen.');
        }

        AuditLog::record($tenantContext->id(), $request->user()->id, 'learning_group.update', 'learning_group', $learningGroup->id, $changes['before'], $changes['after']);

        return back()->with('status', 'Lerngruppe gespeichert.');
    }

    /** Gruppe beenden: alle Gruppenzugänge enden, offene Einladungen verfallen (wie app:learning-group-revoke). */
    public function revoke(Request $request, TenantContext $tenantContext, LearningGroupService $groups, LearningGroup $learningGroup): RedirectResponse
    {
        $this->ensureOwnSchool($tenantContext, $learningGroup);
        if ($learningGroup->status === 'revoked') {
            return back()->withErrors(['group' => 'Diese Lerngruppe ist bereits beendet.']);
        }
        $activeMembers = LearningGroupMember::where('group_id', $learningGroup->id)->where('status', 'active')->count();
        $groups->revokeGroup($learningGroup);

        AuditLog::record($tenantContext->id(), $request->user()->id, 'learning_group.revoke', 'learning_group', $learningGroup->id,
            ['status' => $learningGroup->status], ['status' => 'revoked', 'name' => $learningGroup->name, 'active_members' => $activeMembers]);

        return back()->with('status', 'Lerngruppe beendet.');
    }

    /** Mitglied oder offene Einladung entfernen: beendet nur dessen Gruppenzugänge. */
    public function removeMember(Request $request, TenantContext $tenantContext, LearningGroupService $groups, LearningGroup $learningGroup, string $member): RedirectResponse
    {
        $this->ensureOwnSchool($tenantContext, $learningGroup);
        $row = LearningGroupMember::where('group_id', $learningGroup->id)->whereKey($member)->first();
        abort_unless($row, 404);
        $before = ['member_id' => $row->id, 'user_id' => $row->user_id, 'status' => $row->status, 'group_name' => $learningGroup->name];
        try {
            $groups->removeMember($learningGroup, $row->id);
        } catch (ApiProblem) {
            abort(404);
        }

        AuditLog::record($tenantContext->id(), $request->user()->id, 'learning_group.member_remove', 'learning_group', $learningGroup->id, $before, ['status' => 'removed']);

        return back()->with('status', 'Mitglied entfernt.');
    }

    /** Für Liste und Detailseite: Aktiv, Abgelaufen, Pausiert oder Beendet. */
    public static function statusLabel(LearningGroup $group): string
    {
        return match (true) {
            $group->status === 'revoked' => 'Beendet',
            $group->status === 'suspended' => 'Pausiert',
            $group->valid_until !== null && ! $group->valid_until->isFuture() => 'Abgelaufen',
            $group->valid_from !== null && $group->valid_from->isFuture() => 'Geplant',
            default => 'Aktiv',
        };
    }

    /**
     * @return Collection<int, User> aktive Teilnehmer, als Verwalter wählbar --
     *                               nur Konten, die ihre Einladung angenommen haben (app_user.status
     *                               „active“; eingeladene Konten können sich noch nicht anmelden)
     */
    private function eligibleManagers(string $tenantId): Collection
    {
        return TenantUser::with('user')->where('tenant_id', $tenantId)->where('role', 'learner')->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('status', 'active'))
            ->get()->pluck('user')->filter()->sortBy(fn (User $user) => mb_strtolower($user->name ?? $user->email))->values();
    }

    /**
     * Verwalter serverseitig: ein angemeldetes, aktives Konto (nicht nur
     * eingeladen, nicht gesperrt). Mitgliedschaft und Rolle prüft
     * LearningGroupService::create()/update() selbst.
     */
    private function activeAccount(string $userId): User
    {
        $owner = User::find($userId);
        if (! $owner || $owner->status !== 'active') {
            throw ValidationException::withMessages(['owner_user_id' => 'Der Verwalter muss aktiver Teilnehmer dieser Schule sein.']);
        }

        return $owner;
    }

    private function ensureOwnSchool(TenantContext $tenantContext, LearningGroup $group): void
    {
        abort_unless($group->tenant_id === $tenantContext->id(), 404);
    }
}
