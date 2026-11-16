<?php

namespace App\Workflow;

/**
 * Positivliste der Ereignis-Nutzdaten (WF-CUST1-04).
 *
 * Die Nutzdaten werden ausschließlich aus den Feldern des Ereigniskatalogs
 * neu aufgebaut; alles andere aus der Quelle fällt weg, noch in diesem
 * Aufruf. Erlaubt sind Referenzen, Status, Mengen und Kurs-/Produkt-
 * schlüssel. Nie: Gutschein- oder Verknüpfungscodes, Passwörter, E-Mail-
 * Adressen, Namen, USt-IDs oder sonstige Konto- und Lerndaten.
 *
 * Ein Wert, der nicht zu seiner Wertart passt, lehnt das ganze Ereignis ab
 * (Code ohne Wert), statt still etwas anderes zu senden.
 */
final class PayloadAllowlist
{
    /**
     * Feldnamen, die nie in einem Ereignis stehen dürfen -- auch nicht durch
     * einen künftigen Katalogeintrag (gleiche Regel wie der Validator von
     * Workflow Captain, SPEC-suite-events Abschnitt 5, plus vat/password/link).
     */
    public const FORBIDDEN_NAME = '/(^|_)(code|codes|token|tokens|secret|password|license_key|api_key|email|e_mail|mail|name|first_name|last_name|address|phone|iban|bic|message|note|path|url|ip|vat|vat_id|link|hash|reason)$/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const REF = '/^[A-Za-z0-9._:-]{1,64}$/';

    private const KEY = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/';

    /**
     * @param  array<string, mixed>  $source  Darf mehr enthalten, als erlaubt ist.
     * @return array<string, int|bool|string|null>
     *
     * @throws \InvalidArgumentException mit einem Code wie "payload:quantity:invalid"
     */
    public static function build(string $type, array $source): array
    {
        $spec = EventCatalog::spec($type);
        $data = [];
        foreach ($spec['fields'] as $field => $kind) {
            if (preg_match(self::FORBIDDEN_NAME, $field)) {
                throw new \InvalidArgumentException('payload:'.$field.':forbidden_name');
            }
            $value = $source[$field] ?? null;
            $data[$field] = $value === null ? null : self::value($field, $kind, $value);
        }
        if ($spec['tenant'] === 'required' && empty($data['tenant_id'])) {
            throw new \InvalidArgumentException('payload:tenant_id:required');
        }
        if ($spec['tenant'] === 'none' && array_key_exists('tenant_id', $data)) {
            unset($data['tenant_id']);
        }

        return $data;
    }

    private static function value(string $field, string $kind, mixed $value): int|bool|string
    {
        $ok = match (true) {
            $kind === 'uuid' => is_string($value) && preg_match(self::UUID, strtolower($value)),
            $kind === 'ref' => (is_string($value) || is_int($value)) && preg_match(self::REF, (string) $value),
            $kind === 'key' => is_string($value) && preg_match(self::KEY, $value),
            $kind === 'int' => is_int($value) && $value >= 0 && $value <= 1_000_000,
            $kind === 'bool' => is_bool($value),
            str_starts_with($kind, 'enum:') => is_string($value) && in_array($value, explode('|', substr($kind, 5)), true),
            default => false,
        };
        if (! $ok) {
            throw new \InvalidArgumentException('payload:'.$field.':invalid');
        }

        return match ($kind) {
            'uuid' => strtolower($value),
            'ref' => (string) $value,
            default => $value,
        };
    }
}
