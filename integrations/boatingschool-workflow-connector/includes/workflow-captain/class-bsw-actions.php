<?php

use WorkflowCaptain\Consumer\PermanentFailure;
use WorkflowCaptain\Consumer\TransientFailure;
use WorkflowCaptain\Engine\Action;
use WorkflowCaptain\Engine\ActionContext;

/**
 * Aktionen für Workflow Captain (WorkflowCaptain\Engine\Action): begrenzte
 * Befehle an die Plattform-App (WF-CUST1-07). Nur geladen, wenn Workflow
 * Captain aktiv ist.
 *
 * - Der Idempotenzschlüssel des Schritts geht unverändert an die App; sie
 *   führt denselben Befehl nur einmal aus (idempotent() = true).
 * - Die Schule ist nur Referenz aus dem Ereignis bzw. der Konfiguration;
 *   die App ermittelt sie selbst aus dem Gegenstand und lehnt Abweichungen ab.
 * - Ergebnis nur mit Zahlen und Status, keine Personendaten, keine Codes.
 * - Zustellung, Wiederholung und Sperre bezahlter Codes führt nur der
 *   WooCommerce-Connector aus; dafür gibt es hier bewusst keine Aktion.
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

abstract class BSW_App_Command_Action implements Action
{
    const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    abstract protected function command(): string;

    /** @return array{0: string, 1: array{type:string, id:string}, 2: array} Schule (Referenz), Gegenstand, Parameter */
    abstract protected function request(ActionContext $context): array;

    public function idempotent(): bool
    {
        return true;
    }

    public function execute(ActionContext $context): array
    {
        if (! BSW_License::valid()) {
            throw new PermanentFailure('license_inactive', true);
        }
        if (BSW_Workflow::runtime() !== null) {
            // F-18a: ohne Workflow Captain Pro bzw. ohne dessen Recht keine Befehle.
            throw new PermanentFailure(BSW_Workflow::RUNTIME_NOT_ALLOWED, true);
        }
        [$tenant, $subject, $params] = $this->request($context);
        if (! preg_match(self::UUID, $tenant)) {
            throw new PermanentFailure('tenant_missing');
        }
        $r = BSW_App_Client::request('commands', [
            'op' => 'command',
            'action' => $this->command(),
            'idempotency_key' => $context->idempotency_key,
            'scope' => 'tenant',
            'tenant_id' => $tenant,
            'subject' => $subject,
            'params' => $params,
        ]);
        $now = time();
        if ($r['status'] === 200) {
            BSW_Diagnostics::command(true, 'ok', $now);
            $result = is_array($r['body']['result'] ?? null) ? $r['body']['result'] : [];

            // Für das Protokoll des Laufs nur Zahlen, Wahrheitswerte und kurze Kennwörter -- nie Adressen oder Codes.
            return array_filter($result, static fn ($v) => is_int($v) || is_bool($v) || (is_string($v) && preg_match('/^[a-z0-9_.-]{1,40}$/', $v) === 1))
                + ['repeated' => (bool) ($r['body']['repeated'] ?? false)];
        }
        BSW_Diagnostics::command(false, $r['error'], $now);
        if (BSW_App_Client::retryable($r)) {
            throw new TransientFailure('app_'.$r['error']);
        }
        throw new PermanentFailure('app_'.$r['error']);
    }
}

/** Hinweis an die Schuladmins der Schule, auf die sich das Ereignis bezieht (feste Vorlage). */
final class BSW_School_Admin_Notice_Action extends BSW_App_Command_Action
{
    const TEMPLATES = ['codes_delivered', 'codes_revoked', 'shop_account_linked', 'shop_account_deactivated', 'provisioning_failed'];

    public function key(): string
    {
        return 'xboatingschoolconnector.school_admin.notice';
    }

    public function label(): string
    {
        return __('Bootsschule: Hinweis an die Schuladmins', 'boatingschool-workflow-connector');
    }

    /** Wer im Shop Bestellungen und Kundenkonten betreut, darf die Schule auch so benachrichtigen. */
    public function capability(): ?string
    {
        return 'manage_woocommerce';
    }

    public function validate_config(array $config): array
    {
        return in_array($config['template'] ?? null, self::TEMPLATES, true) ? [] : ['template:invalid'];
    }

    protected function command(): string
    {
        return 'school_admin.notice';
    }

    protected function request(ActionContext $context): array
    {
        $envelope = $context->envelope;
        if (($envelope['source']['product'] ?? '') !== BSW_Manifest::KEY) {
            throw new PermanentFailure('event_not_from_boatingschool');
        }
        $subject = (array) ($envelope['subject'] ?? []);

        return [
            (string) ($envelope['data']['tenant_id'] ?? ''),
            ['type' => (string) ($subject['type'] ?? ''), 'id' => (string) ($subject['id'] ?? '')],
            ['template' => (string) $context->config['template']],
        ];
    }
}

/**
 * Optional: ein Mitglied der Schule in eine Lerngruppe einladen. In der App
 * standardmäßig abgeschaltet (WORKFLOW_ACTION_GROUP_INVITE). Alle Kennungen
 * stehen in der Konfiguration des Schritts; die App prüft Rolle,
 * Mitgliedschaft, bestätigte Adresse, Plätze und Tagesgrenzen.
 */
final class BSW_Learning_Group_Invite_Action extends BSW_App_Command_Action
{
    const FIELDS = ['tenant_id', 'group_id', 'user_id', 'acting_user_id'];

    public function key(): string
    {
        return 'xboatingschoolconnector.learning_group.invite';
    }

    public function label(): string
    {
        return __('Bootsschule: Mitglied in Lerngruppe einladen', 'boatingschool-workflow-connector');
    }

    public function capability(): ?string
    {
        return 'manage_woocommerce';
    }

    public function validate_config(array $config): array
    {
        $codes = [];
        foreach (self::FIELDS as $field) {
            if (! is_string($config[$field] ?? null) || ! preg_match(self::UUID, $config[$field])) {
                $codes[] = $field.':invalid';
            }
        }

        return $codes;
    }

    protected function command(): string
    {
        return 'learning_group.invite';
    }

    protected function request(ActionContext $context): array
    {
        $c = $context->config;

        return [
            (string) ($c['tenant_id'] ?? ''),
            ['type' => 'learning_group', 'id' => (string) ($c['group_id'] ?? '')],
            ['user_id' => (string) ($c['user_id'] ?? ''), 'acting_user_id' => (string) ($c['acting_user_id'] ?? '')],
        ];
    }
}
