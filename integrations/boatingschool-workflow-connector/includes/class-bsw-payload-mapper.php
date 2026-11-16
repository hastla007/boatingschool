<?php

/**
 * Payload-Mapping (WF-CUST1-08): Ereignis der App -> Ereignis für
 * Workflow Captain (Consumer::capture()).
 *
 * Positivliste: "data" entsteht nur aus den Feldern, die BSW_Manifest für
 * Typ und Version nennt; jeder Wert wird gegen seine Wertart geprüft. Ein
 * unbekannter Typ, eine unbekannte Version oder ein falscher Wert lehnt das
 * Ereignis mit einem Code ab (ohne Wert). Die Event-ID der App ist der
 * Diskriminator: eine erneute Zustellung desselben Ereignisses ergibt in
 * Workflow Captain denselben Idempotenzschlüssel (R-7.8, R-7.10).
 */
defined('ABSPATH') || defined('BSW_CONNECTOR_TESTING') || exit;

final class BSW_Payload_Mapper
{
    const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    const REF = '/^[A-Za-z0-9._:-]{1,64}$/';

    const KEY = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    const SUBJECTS = ['shop_account', 'code_batch'];

    /**
     * @param  mixed  $event  "event" aus dem Body der App
     * @return array|string Ereignis für Consumer::capture() oder ein Fehlercode
     */
    public static function map($event)
    {
        if (! is_array($event)) {
            return 'event:invalid';
        }
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        if (! isset(BSW_Manifest::APP_EVENTS[$type])) {
            return 'event:unknown_type';
        }
        [$version, $fields] = BSW_Manifest::APP_EVENTS[$type];
        if (($event['version'] ?? null) !== $version) {
            return 'event:unsupported_version';
        }
        $id = is_string($event['id'] ?? null) ? strtolower($event['id']) : '';
        if (! preg_match(self::UUID, $id)) {
            return 'event:id_invalid';
        }
        $subject = $event['subject'] ?? null;
        if (! is_array($subject) || ! in_array($subject['type'] ?? null, self::SUBJECTS, true) || ! is_string($subject['id'] ?? null) || ! preg_match(self::UUID, $subject['id'])) {
            return 'event:subject_invalid';
        }
        $source = is_array($event['data'] ?? null) ? $event['data'] : [];
        $data = [];
        foreach ($fields as $field => $kind) {
            $value = $source[$field] ?? null;
            if ($value !== null && ! self::valid($kind, $value)) {
                return 'data:'.$field.':invalid';
            }
            $data[$field] = $value;
        }
        $tenant = $event['tenant_id'] ?? null;
        if (array_key_exists('tenant_id', $fields) && $tenant !== ($data['tenant_id'] ?? null)) {
            return 'event:tenant_mismatch';
        }

        return [
            'type' => BSW_Manifest::type($type),
            'subject' => ['type' => $subject['type'], 'id' => $subject['id'], 'id_kind' => 'public'],
            'data' => $data,
            'discriminator' => $id,
        ];
    }

    /** Testereignis der Admin-Seite: derselbe Weg wie ein echtes Ereignis, ohne Daten der App. */
    public static function test_event(int $now): array
    {
        $at = gmdate('Y-m-d\TH:i:s\Z', $now);

        return [
            'type' => BSW_Manifest::type(BSW_Manifest::TEST_TYPE),
            'subject' => ['type' => 'connector', 'id' => BSW_Manifest::KEY, 'id_kind' => 'public'],
            'data' => ['sent_at' => $at],
            'discriminator' => $at,
        ];
    }

    private static function valid(string $kind, $value): bool
    {
        if ($kind === 'uuid') {
            return is_string($value) && preg_match(self::UUID, $value) === 1;
        }
        if ($kind === 'ref') {
            return is_string($value) && preg_match(self::REF, $value) === 1;
        }
        if ($kind === 'key') {
            return is_string($value) && preg_match(self::KEY, $value) === 1;
        }
        if ($kind === 'int') {
            return is_int($value) && $value >= 0 && $value <= 1000000;
        }
        if ($kind === 'bool') {
            return is_bool($value);
        }
        if (strpos($kind, 'enum:') === 0) {
            return is_string($value) && in_array($value, explode('|', substr($kind, 5)), true);
        }

        return false;
    }
}
