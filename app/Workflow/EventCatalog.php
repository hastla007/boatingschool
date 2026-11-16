<?php

namespace App\Workflow;

/**
 * Versionierte Ereignisse des Workflow-Vertrags (WF-CUST1-03, docs/workflow/README.md).
 *
 * Je Typ: Version, Gegenstand, ob eine Schule dazugehört (tenant: required,
 * optional, none) und die Positivliste der Felder mit ihrer Wertart
 * (PayloadAllowlist). Eine neue Pflichtangabe oder eine geänderte Bedeutung
 * ist eine neue Version des Typs; neue optionale Felder nicht.
 *
 * Feldnamen folgen den Regeln für verbotene Werte des Workflow-Captain-
 * Vertrags (kein Name, der auf code, email, name, token … endet): der Kurs-
 * bzw. Produktcode der Plattform ist ein öffentlicher Katalogschlüssel und
 * heißt deshalb course_key/product_key.
 */
final class EventCatalog
{
    public const SHOP_ACCOUNT_REGISTERED = 'shop_account.registered';

    public const SHOP_ACCOUNT_LINKED = 'shop_account.linked';

    public const SHOP_ACCOUNT_DEACTIVATED = 'shop_account.deactivated';

    public const PROVISIONING_SUCCEEDED = 'provisioning.succeeded';

    public const PROVISIONING_FAILED = 'provisioning.failed';

    public const CODE_BATCH_DELIVERED = 'code_batch.delivered';

    public const CODE_BATCH_REVOKED = 'code_batch.revoked';

    /**
     * Wertarten: uuid, ref (Kennung aus dem Shop, z. B. Bestell-ID), key
     * (Katalogschlüssel), int, bool, enum:a|b|c.
     *
     * @var array<string, array{version:int, subject:string, tenant:string, fields:array<string,string>}>
     */
    public const TYPES = [
        self::SHOP_ACCOUNT_REGISTERED => [
            'version' => 1, 'subject' => 'shop_account', 'tenant' => 'none',
            // „wartet auf Verknüpfung“ erscheint wie „in Prüfung“ (verrät sonst,
            // ob eine Adresse ein App-Konto hat, wie ShopAccounts::present()).
            'fields' => ['shop_account_id' => 'uuid', 'status' => 'enum:pending_review'],
        ],
        self::SHOP_ACCOUNT_LINKED => [
            'version' => 1, 'subject' => 'shop_account', 'tenant' => 'required',
            'fields' => ['shop_account_id' => 'uuid', 'tenant_id' => 'uuid', 'status' => 'enum:linked'],
        ],
        self::SHOP_ACCOUNT_DEACTIVATED => [
            'version' => 1, 'subject' => 'shop_account', 'tenant' => 'optional',
            'fields' => ['shop_account_id' => 'uuid', 'tenant_id' => 'uuid', 'status' => 'enum:revoked|rejected'],
        ],
        self::PROVISIONING_SUCCEEDED => [
            'version' => 1, 'subject' => 'shop_account', 'tenant' => 'required',
            'fields' => ['shop_account_id' => 'uuid', 'tenant_id' => 'uuid', 'status' => 'enum:done|needs_link'],
        ],
        self::PROVISIONING_FAILED => [
            'version' => 1, 'subject' => 'shop_account', 'tenant' => 'required',
            'fields' => ['shop_account_id' => 'uuid', 'tenant_id' => 'uuid', 'attempts' => 'int', 'final' => 'bool'],
        ],
        self::CODE_BATCH_DELIVERED => [
            'version' => 1, 'subject' => 'code_batch', 'tenant' => 'required',
            'fields' => [
                'batch_id' => 'uuid', 'tenant_id' => 'uuid', 'shop_account_id' => 'uuid', 'order_id' => 'ref', 'order_line_id' => 'ref',
                'kind' => 'enum:course|renewal|product', 'quantity' => 'int', 'course_key' => 'key', 'product_key' => 'key',
            ],
        ],
        self::CODE_BATCH_REVOKED => [
            'version' => 1, 'subject' => 'code_batch', 'tenant' => 'required',
            'fields' => [
                'batch_id' => 'uuid', 'tenant_id' => 'uuid', 'order_id' => 'ref', 'order_line_id' => 'ref',
                'voided' => 'int', 'redeemed' => 'int', 'open' => 'int', 'status' => 'enum:voided|partially_voided',
            ],
        ],
    ];

    public static function has(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /** @return array{version:int, subject:string, tenant:string, fields:array<string,string>} */
    public static function spec(string $type): array
    {
        if (! self::has($type)) {
            throw new \InvalidArgumentException('event:unknown_type');
        }

        return self::TYPES[$type];
    }

    /** Für den Handshake: Typ, Version und Felder (ohne Werte). @return list<array{type:string, version:int, subject:string, fields:array<string,string>}> */
    public static function describe(): array
    {
        $out = [];
        foreach (self::TYPES as $type => $spec) {
            $out[] = ['type' => $type, 'version' => $spec['version'], 'subject' => $spec['subject'], 'fields' => $spec['fields']];
        }

        return $out;
    }
}
