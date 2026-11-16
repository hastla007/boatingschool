<?php

/**
 * Manifest für Workflow Captain (öffentlicher Connector-Vertrag v1,
 * workflow_captain_register_connectors_v1) und Zuordnung der Ereignisse der
 * Plattform-App zu den Ereignistypen des Connectors.
 *
 * Schlüssel "boatingschool-connector", Präfix der Typen und Aktionen
 * "xboatingschoolconnector". Alle Felder sind Referenzen, Status oder
 * Mengen (Datenklasse "normal"); personenbezogene Felder gibt es nicht.
 * Die Feldliste je Typ ist die Positivliste: Workflow Captain baut "data"
 * daraus neu auf, und dieses Plugin übernimmt vorher ebenfalls nur sie.
 *
 * Die App-Seite steht in App\Workflow\EventCatalog (gleiche Typen, Versionen
 * und Felder); ein Test der App vergleicht beide.
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Manifest
{
    const KEY = 'boatingschool-connector';

    const PREFIX = 'xboatingschoolconnector';

    /** Version des Workflow-Vertrags der App, die dieses Plugin spricht (MAJOR.MINOR.PATCH). */
    const CONTRACT_VERSION = '1.0.0';

    /** Mindestversion von WORKFLOW_CAPTAIN_CONTRACT_VERSION. */
    const REQUIRES_WORKFLOW_CAPTAIN = '0.1.0';

    /** Typ des Testereignisses (Admin-Seite „Testereignis senden“). */
    const TEST_TYPE = 'connector.tested';

    /**
     * Ereignisse der App: Typ => [Version, Felder (Name => Wertart der App)].
     * Wertarten: uuid, ref, key, int, bool, enum:a|b.
     */
    const APP_EVENTS = [
        'shop_account.registered' => [1, ['shop_account_id' => 'uuid', 'status' => 'enum:pending_review']],
        'shop_account.linked' => [1, ['shop_account_id' => 'uuid', 'tenant_id' => 'uuid', 'status' => 'enum:linked']],
        'shop_account.deactivated' => [1, ['shop_account_id' => 'uuid', 'tenant_id' => 'uuid', 'status' => 'enum:revoked|rejected']],
        'provisioning.succeeded' => [1, ['shop_account_id' => 'uuid', 'tenant_id' => 'uuid', 'status' => 'enum:done|needs_link']],
        'provisioning.failed' => [1, ['shop_account_id' => 'uuid', 'tenant_id' => 'uuid', 'attempts' => 'int', 'final' => 'bool']],
        'code_batch.delivered' => [1, [
            'batch_id' => 'uuid', 'tenant_id' => 'uuid', 'shop_account_id' => 'uuid', 'order_id' => 'ref', 'order_line_id' => 'ref',
            'kind' => 'enum:course|renewal|product', 'quantity' => 'int', 'course_key' => 'key', 'product_key' => 'key',
        ]],
        'code_batch.revoked' => [1, [
            'batch_id' => 'uuid', 'tenant_id' => 'uuid', 'order_id' => 'ref', 'order_line_id' => 'ref',
            'voided' => 'int', 'redeemed' => 'int', 'open' => 'int', 'status' => 'enum:voided|partially_voided',
        ]],
    ];

    /** Befehle an die App (Aktionen in Workflow Captain): Aktionsschlüssel => Befehl der App. */
    const ACTIONS = [
        'xboatingschoolconnector.school_admin.notice' => 'school_admin.notice',
        'xboatingschoolconnector.learning_group.invite' => 'learning_group.invite',
    ];

    /** Bedeutung von actor_user_id: es gibt keinen WordPress-Benutzer zu einem Ereignis der App. */
    const ACTOR = 'not set: events come from the platform app, not from a WordPress user';

    public static function type(string $app_type): string
    {
        return self::PREFIX.'.'.$app_type;
    }

    /** Datentyp im Manifest von Workflow Captain je Wertart der App. */
    public static function field_type(string $kind): string
    {
        if ($kind === 'int') {
            return 'integer';
        }

        return $kind === 'bool' ? 'boolean' : 'string';
    }

    public static function get(string $version): array
    {
        $events = [];
        foreach (self::APP_EVENTS as $app_type => [, $fields]) {
            $events[self::type($app_type)] = [
                'fields' => array_map(static fn (string $kind) => ['type' => self::field_type($kind), 'class' => 'normal'], $fields),
                'after_commit' => true,
                'actor' => self::ACTOR,
            ];
        }
        $events[self::type(self::TEST_TYPE)] = [
            'fields' => ['sent_at' => ['type' => 'datetime', 'class' => 'normal']],
            'after_commit' => true,
            'actor' => 'administrator who sent the test event',
        ];

        return [
            'key' => self::KEY,
            'name' => 'BoatingSchool Workflow Connector',
            'version' => $version,
            'contract_version' => self::CONTRACT_VERSION,
            'requires' => ['workflow_captain' => self::REQUIRES_WORKFLOW_CAPTAIN, 'boatingschool' => self::CONTRACT_VERSION],
            'events' => $events,
            'actions' => array_keys(self::ACTIONS),
            'description' => 'Ereignisse der Bootsschul-Plattform (Shopkonten, Provisionierung, Code-Batches) und begrenzte Befehle an die Plattform.',
        ];
    }
}
