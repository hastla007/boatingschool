<?php

use WorkflowCaptain\Plugin;

/**
 * Anbindung an Workflow Captain über dessen öffentlichen Connector-Vertrag v1
 * (freier Kern): Anmeldung an workflow_captain_register_connectors_v1 mit dem
 * Manifest, Aktionen an workflow_captain_actions_v1, Erfassen über
 * Consumer::capture(). Workflow Captain Pro ist dafür nicht nötig (ob es
 * künftig nötig wird, ist F-18a); fehlt Workflow Captain ganz, bleiben die
 * Hooks wirkungslos und die Statusseite sagt es.
 *
 * Die Klassen, die Schnittstellen von Workflow Captain implementieren, liegen
 * unter includes/workflow-captain/ und werden erst geladen, wenn diese
 * Schnittstellen existieren.
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Workflow
{
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
            self::load();
            $adapter = new BSW_Adapter;
            $accepted = (bool) $registry->add(BSW_Manifest::get(BSW_CONNECTOR_VERSION), $adapter);
            self::$adapter = $accepted ? $adapter : null;
            self::$registration = $accepted ? 'accepted' : 'refused';
            BSW_Diagnostics::registration(self::$registration);
        } catch (Throwable $e) {
            self::$registration = 'exception';
            BSW_Diagnostics::registration('exception');
        }
    }

    /** @param object $registry WorkflowCaptain\Engine\ActionRegistry */
    public static function register_actions($registry): void
    {
        if (! interface_exists('\WorkflowCaptain\Engine\Action')) {
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
