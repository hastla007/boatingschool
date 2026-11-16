<?php

use WorkflowCaptain\Plugin;

/**
 * Anbindung an Workflow Captain über dessen öffentlichen Connector-Vertrag v1:
 * Anmeldung an workflow_captain_register_connectors_v1 mit dem Manifest,
 * Aktionen an workflow_captain_actions_v1, Erfassen über Consumer::capture().
 * Fehlt Workflow Captain ganz, bleiben die Hooks wirkungslos und die
 * Statusseite sagt es.
 *
 * Laufzeit (Entscheidung des Inhabers F-18a, 16.11.2026): Kundenconnectoren
 * laufen nur mit Workflow Captain Pro und dessen Recht
 * workflow.custom_connectors. Ohne Workflow Captain Pro meldet sich der
 * Connector nicht an (workflow_captain_pro_missing); fehlt das Recht, lehnt
 * Workflow Captain die Anmeldung über den Filter
 * workflow_captain_connector_runtime_allowed_v1 ab (runtime_not_allowed).
 * Das ist eine technische Abhängigkeit; die eigene Lizenz prüft BSW_License.
 *
 * Die Klassen, die Schnittstellen von Workflow Captain implementieren, liegen
 * unter includes/workflow-captain/ und werden erst geladen, wenn diese
 * Schnittstellen existieren.
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Workflow
{
    const PRO_MISSING = 'workflow_captain_pro_missing';

    const RUNTIME_NOT_ALLOWED = 'runtime_not_allowed';

    /** Option der Freigabe in Workflow Captain (ConnectorRegistry::OPTION). */
    const RELEASE_OPTION = 'workflow_captain_connectors';

    /** @var object|null Adapter (BSW_Adapter), wenn angenommen */
    private static $adapter = null;

    /** @var string|null Ergebnis der letzten Anmeldung in diesem Request */
    private static $registration = null;

    public static function init(): void
    {
        add_action('workflow_captain_register_connectors_v1', [__CLASS__, 'register']);
        add_action('workflow_captain_actions_v1', [__CLASS__, 'register_actions']);
    }

    public static function available(): bool
    {
        return interface_exists('\WorkflowCaptain\Adapter\Adapter') && class_exists('\WorkflowCaptain\Plugin');
    }

    /**
     * Laufzeit nach F-18a: null, wenn Workflow Captain Pro aktiv ist und Workflow
     * Captain die Anmeldung nicht wegen des fehlenden Rechts abgelehnt hat; sonst
     * workflow_captain_pro_missing oder runtime_not_allowed.
     */
    public static function runtime(): ?string
    {
        if (! defined('WORKFLOW_CAPTAIN_PRO_VERSION')) {
            return self::PRO_MISSING;
        }

        return self::$registration === self::RUNTIME_NOT_ALLOWED ? self::RUNTIME_NOT_ALLOWED : null;
    }

    private static function load(): void
    {
        require_once __DIR__.'/workflow-captain/class-bsw-adapter.php';
        require_once __DIR__.'/workflow-captain/class-bsw-actions.php';
    }

    /** @param object $registry WorkflowCaptain\Connector\ConnectorRegistry */
    public static function register($registry): void
    {
        try {
            if (! self::available()) {
                return;
            }
            if (! defined('WORKFLOW_CAPTAIN_PRO_VERSION')) {
                self::$adapter = null;
                self::$registration = self::PRO_MISSING;
                BSW_Diagnostics::registration(self::PRO_MISSING);

                return;
            }
            self::load();
            $adapter = new BSW_Adapter;
            $accepted = (bool) $registry->add(BSW_Manifest::get(BSW_CONNECTOR_VERSION), $adapter);
            self::$adapter = $accepted ? $adapter : null;
            $reason = (! $accepted && method_exists($registry, 'reason')) ? $registry->reason(BSW_Manifest::KEY) : null;
            self::$registration = $accepted ? 'accepted' : ($reason === self::RUNTIME_NOT_ALLOWED ? self::RUNTIME_NOT_ALLOWED : 'refused');
            BSW_Diagnostics::registration(self::$registration);
        } catch (Throwable $e) {
            self::$registration = 'exception';
            BSW_Diagnostics::registration('exception');
        }
    }

    /** @param object $registry WorkflowCaptain\Engine\ActionRegistry */
    public static function register_actions($registry): void
    {
        if (! interface_exists('\WorkflowCaptain\Engine\Action') || ! defined('WORKFLOW_CAPTAIN_PRO_VERSION')) {
            return;
        }
        self::load();
        $registry->add(new BSW_School_Admin_Notice_Action);
        $registry->add(new BSW_Learning_Group_Invite_Action);
    }

    public static function registered(): bool
    {
        return self::$adapter !== null;
    }

    public static function registration(): ?string
    {
        return self::$registration;
    }

    /** Vom Betreiber unter „Workflow Captain → Eigene Connectoren“ freigegeben? */
    public static function enabled(): bool
    {
        $release = get_option(self::RELEASE_OPTION, []);

        return is_array($release) && ! empty($release[BSW_Manifest::KEY]['enabled']);
    }

    /** @return string captured, duplicate, rejected oder failed */
    public static function capture(array $event): string
    {
        if (self::$adapter === null) {
            return 'failed';
        }
        try {
            return (string) Plugin::instance()->consumer()->capture(self::$adapter, $event);
        } catch (Throwable $e) {
            return 'failed';
        }
    }

    /** Für Tests: Anmeldestand zurücksetzen. */
    public static function reset(): void
    {
        self::$adapter = null;
        self::$registration = null;
    }
}
