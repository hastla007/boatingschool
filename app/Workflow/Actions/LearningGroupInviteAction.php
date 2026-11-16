<?php

namespace App\Workflow\Actions;

use App\Api\ApiProblem;
use App\Models\LearningGroup;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\LearningGroupService;

/**
 * Optional (Standard: aus): ein Mitglied der Schule in eine Lerngruppe
 * einladen (WF-CUST1-07), ausschließlich über LearningGroupService::invite()
 * -- dort gelten Sitzplätze, Tages- und Stundengrenzen wie für einen
 * Menschen.
 *
 * Rollenprüfung: der Befehl handelt im Namen des Verwalters der Gruppe
 * (acting_user_id muss owner_user_id sein und aktives Mitglied der Schule).
 * Eingeladen wird nur, wer schon aktives Mitglied der Schule ist und eine
 * bestätigte Adresse hat; die Adresse löst die App selbst auf, sie steht nie
 * im Workflow. Das Ergebnis enthält nur Zahlen, kein Einladungs-Token.
 */
final class LearningGroupInviteAction implements WorkflowAction
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(private LearningGroupService $groups) {}

    public function name(): string
    {
        return 'learning_group.invite';
    }

    public function subjectTypes(): array
    {
        return ['learning_group'];
    }

    public function validate(array $params): array
    {
        $codes = [];
        foreach (['user_id', 'acting_user_id'] as $field) {
            if (! is_string($params[$field] ?? null) || ! preg_match(self::UUID, $params[$field])) {
                $codes[] = $field.':invalid';
            }
        }
        if (array_diff(array_keys($params), ['user_id', 'acting_user_id']) !== []) {
            $codes[] = 'params:unknown_field';
        }

        return $codes;
    }

    public function execute(Tenant $tenant, array $subject, array $params): array
    {
        // Unter RLS: nur Gruppen der aktuellen Schule sind sichtbar.
        $group = LearningGroup::whereKey($subject['id'])->where('tenant_id', $tenant->id)->first();
        if (! $group) {
            throw new ApiProblem(404, 'SUBJECT_NOT_FOUND', 'Gegenstand nicht gefunden');
        }
        if ($group->owner_user_id !== $params['acting_user_id'] || ! $this->activeMember($tenant, $params['acting_user_id'])) {
            throw new ApiProblem(403, 'ROLE_NOT_ALLOWED', 'Keine Berechtigung', 'Nur der aktive Verwalter der Gruppe darf einladen.');
        }
        $invitee = User::whereKey($params['user_id'])->where('status', 'active')->whereNotNull('email_verified_at')->first();
        if (! $invitee || ! $this->activeMember($tenant, $invitee->id)) {
            throw new ApiProblem(422, 'INVITEE_NOT_ELIGIBLE', 'Einladung nicht möglich', 'Nur aktive Mitglieder der Schule mit bestätigter Adresse.');
        }
        $owner = User::findOrFail($group->owner_user_id);
        $result = $this->groups->invite($group, $owner, [$invitee->email]);

        return ['invited' => (int) $result['invited'], 'skipped' => count($result['skipped'])];
    }

    private function activeMember(Tenant $tenant, string $userId): bool
    {
        return TenantUser::where('tenant_id', $tenant->id)->where('user_id', $userId)->where('status', 'active')->exists();
    }
}
