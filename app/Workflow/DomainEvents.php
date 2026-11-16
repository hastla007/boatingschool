<?php

namespace App\Workflow;

use App\Models\CommerceEvent;
use App\Models\CommerceShopAccount;
use App\Models\CouponBatch;
use App\Models\CourseDefinition;
use App\Models\Product;

/**
 * Brücke von den dokumentierten Laravel-Services zu den Workflow-Ereignissen
 * (WF-CUST1-03): ShopAccounts, ShopOrders und Commerce\Outbox rufen je
 * Vorgang genau eine Methode auf, innerhalb ihrer Transaktion. Nicht aus
 * Model-Hooks abgeleitet: nur Vorgänge, die ein Service belegt ausführt.
 *
 * Jede Methode übergibt nur Referenzen und Zahlen an die Positivliste; was
 * das Modell sonst enthält (Adresse, Name, USt-ID, Codes), wird gar nicht
 * erst gelesen.
 */
final class DomainEvents
{
    public static function shopAccountRegistered(CommerceShopAccount $account): void
    {
        WorkflowOutbox::record(EventCatalog::SHOP_ACCOUNT_REGISTERED, $account->id, [
            'shop_account_id' => $account->id,
            'status' => CommerceShopAccount::PENDING_REVIEW,
        ], 'once', self::accountCorrelation($account));
    }

    public static function shopAccountLinked(CommerceShopAccount $account): void
    {
        WorkflowOutbox::record(EventCatalog::SHOP_ACCOUNT_LINKED, $account->id, [
            'shop_account_id' => $account->id,
            'tenant_id' => $account->tenant_id,
            'status' => CommerceShopAccount::LINKED,
        ], 'linked:'.$account->tenant_id.':'.$account->linked_at?->format('U.u'), self::accountCorrelation($account));
    }

    public static function shopAccountDeactivated(CommerceShopAccount $account): void
    {
        WorkflowOutbox::record(EventCatalog::SHOP_ACCOUNT_DEACTIVATED, $account->id, [
            'shop_account_id' => $account->id,
            'tenant_id' => $account->tenant_id,
            'status' => $account->status,
        ], 'deactivated:'.($account->revoked_at?->format('U.u') ?? $account->status), self::accountCorrelation($account));
    }

    /** Provisionierung App -> Shop erledigt (done) oder wartet auf Verknüpfung (needs_link). */
    public static function provisioningSucceeded(CommerceEvent $provision, CommerceShopAccount $account, string $status): void
    {
        WorkflowOutbox::record(EventCatalog::PROVISIONING_SUCCEEDED, $account->id, [
            'shop_account_id' => $account->id,
            'tenant_id' => $account->tenant_id,
            'status' => $status,
        ], 'provision:'.$provision->id, self::accountCorrelation($account), 'pgsql_admin');
    }

    public static function provisioningFailed(CommerceEvent $provision, CommerceShopAccount $account, int $attempts, bool $final): void
    {
        WorkflowOutbox::record(EventCatalog::PROVISIONING_FAILED, $account->id, [
            'shop_account_id' => $account->id,
            'tenant_id' => $account->tenant_id,
            'attempts' => $attempts,
            'final' => $final,
        ], 'provision:'.$provision->id.':'.$attempts, self::accountCorrelation($account), 'pgsql_admin');
    }

    public static function codeBatchDelivered(CouponBatch $batch): void
    {
        WorkflowOutbox::record(EventCatalog::CODE_BATCH_DELIVERED, $batch->id, [
            'batch_id' => $batch->id,
            'tenant_id' => $batch->tenant_id,
            'shop_account_id' => $batch->shop_account_id,
            'order_id' => $batch->order_id,
            'order_line_id' => $batch->order_line_id,
            'kind' => $batch->kind,
            'quantity' => (int) $batch->quantity,
            'course_key' => $batch->course_id ? CourseDefinition::withoutGlobalScopes()->whereKey($batch->course_id)->value('code') : null,
            'product_key' => $batch->product_id ? Product::whereKey($batch->product_id)->value('code') : null,
        ], 'once', self::orderCorrelation($batch));
    }

    /** @param array{voided:int, redeemed:int, open:int} $result */
    public static function codeBatchRevoked(CouponBatch $batch, array $result, string $idempotencyKey): void
    {
        WorkflowOutbox::record(EventCatalog::CODE_BATCH_REVOKED, $batch->id, [
            'batch_id' => $batch->id,
            'tenant_id' => $batch->tenant_id,
            'order_id' => $batch->order_id,
            'order_line_id' => $batch->order_line_id,
            'voided' => (int) $result['voided'],
            'redeemed' => (int) $result['redeemed'],
            'open' => (int) $result['open'],
            'status' => $batch->status,
        ], 'void:'.hash('sha256', $idempotencyKey), self::orderCorrelation($batch));
    }

    /** Je Shopkonto, ohne Adresse oder Shop-Kundennummer. */
    private static function accountCorrelation(CommerceShopAccount $account): string
    {
        return 'shop_account:'.$account->id;
    }

    /** Je Shop und Bestellung: Lieferung und spätere Sperre derselben Bestellung gehören zusammen. */
    private static function orderCorrelation(CouponBatch $batch): string
    {
        return 'order:'.substr(hash('sha256', $batch->shop_id.'|'.$batch->order_id), 0, 32);
    }
}
