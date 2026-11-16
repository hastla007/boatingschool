<?php

/**
 * Eigene Lizenz des Connectors (Entscheidung 17): eigenes Projektprodukt mit
 * Produktschlüssel "boatingschool-workflow-connector", Präfix
 * "boatingschool_workflow" und Recht "boatingschool_workflow.connector"
 * (wplc-catalog.json). Workflow Captain prüft keine Lizenz, und eine
 * Pro-Lizenz eines Captain-Produkts deckt diesen Connector nicht ab.
 *
 * Die Lizenzbibliothek (eigene Kopie des Lizenz-Clients) liegt NICHT in
 * diesem öffentlichen Repository. Der Paketbau legt sie unter
 * lib/license-client.php ab; die Datei gibt ein Objekt mit
 * is_entitled(string): bool zurück (z. B. den LicenseManager des Clients,
 * eingerichtet mit Produktschlüssel, Version, Server und öffentlichen
 * Schlüsseln). Fehlt sie, gilt der Connector als nicht lizenziert.
 *
 * Fail-closed: ohne gültige Lizenz nimmt der Connector keine neuen
 * Ereignisse an und führt keine Befehle aus. Bereits Erfasstes bleibt in
 * Workflow Captain unberührt.
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_License
{
    const PRODUCT_KEY = 'boatingschool-workflow-connector';

    const PREFIX = 'boatingschool_workflow';

    const ENTITLEMENT = 'boatingschool_workflow.connector';

    const VALID = 'valid';

    const INVALID = 'invalid';

    const LIBRARY_MISSING = 'library_missing';

    /** @var callable|null Für Tests: liefert den Status statt der Bibliothek. */
    private static $checker = null;

    /** @var string|null je Request gemerkt */
    private static $status = null;

    public static function use_checker(?callable $checker): void
    {
        self::$checker = $checker;
        self::$status = null;
    }

    public static function status(): string
    {
        if (self::$status !== null) {
            return self::$status;
        }
        if (self::$checker !== null) {
            return self::$status = (string) (self::$checker)();
        }
        $library = dirname(__DIR__).'/lib/license-client.php';
        if (! is_readable($library)) {
            return self::$status = self::LIBRARY_MISSING;
        }
        try {
            $manager = require $library;

            return self::$status = is_object($manager) && method_exists($manager, 'is_entitled') && $manager->is_entitled(self::ENTITLEMENT)
                ? self::VALID : self::INVALID;
        } catch (Throwable $e) {
            return self::$status = self::INVALID;
        }
    }

    public static function valid(): bool
    {
        return self::status() === self::VALID;
    }
}
