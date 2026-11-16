<?php

namespace App\Workflow\Actions;

use App\Models\Tenant;

/**
 * Ein Befehl, den der Workflow Connector auslösen darf (WF-CUST1-07).
 *
 * execute() läuft auf der App-Verbindung (Rolle boatingschool_app) mit
 * gesetztem RLS-Kontext der serverseitig ermittelten Schule und ruft nur
 * öffentliche Services der App auf. Das Ergebnis ist für das Protokoll des
 * Workflows bestimmt: nur Zahlen und Status, keine Personendaten, keine
 * Codes, keine Tokens.
 */
interface WorkflowAction
{
    public function name(): string;

    /** Gegenstände, auf die sich der Befehl beziehen darf (shop_account, code_batch, learning_group). @return list<string> */
    public function subjectTypes(): array;

    /** @return list<string> Fehlercodes der Parameter, leer wenn gültig */
    public function validate(array $params): array;

    /**
     * @param  array{type:string, id:string}  $subject
     * @return array<string, int|bool|string|null>
     */
    public function execute(Tenant $tenant, array $subject, array $params): array;
}
