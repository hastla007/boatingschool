<?php

namespace App\Workflow\Actions;

use App\Models\Tenant;
use App\Notifications\WorkflowSchoolAdminNotice;
use App\Support\BestEffortMail;
use Illuminate\Support\Facades\Notification;

/**
 * Hinweis an die Schuladmins (owner/admin) der Schule (WF-CUST1-07).
 *
 * Nur feste Vorlagen, kein freier Text und keine Empfängeradresse aus dem
 * Workflow: die Empfänger ermittelt die App selbst (Tenant::admins() unter
 * RLS), der Inhalt nennt keine Codes und keine Personendaten.
 */
final class SchoolAdminNoticeAction implements WorkflowAction
{
    public function name(): string
    {
        return 'school_admin.notice';
    }

    public function subjectTypes(): array
    {
        return ['shop_account', 'code_batch', 'tenant'];
    }

    public function validate(array $params): array
    {
        $template = $params['template'] ?? null;
        $unknown = array_diff(array_keys($params), ['template']);

        return array_values(array_filter([
            is_string($template) && isset(WorkflowSchoolAdminNotice::TEMPLATES[$template]) ? null : 'template:invalid',
            $unknown === [] ? null : 'params:unknown_field',
        ]));
    }

    public function execute(Tenant $tenant, array $subject, array $params): array
    {
        $admins = $tenant->admins()->filter();
        if ($admins->isNotEmpty()) {
            BestEffortMail::send(
                fn () => Notification::send($admins, new WorkflowSchoolAdminNotice((string) $params['template'], (string) $tenant->name)),
                'Workflow-Hinweis '.$tenant->id
            );
        }

        return ['recipients' => $admins->count(), 'template' => (string) $params['template']];
    }
}
