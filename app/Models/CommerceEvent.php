<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Protokoll der Shop-Anbindung (eingehend) und Ausgang an den Shop (Outbox). Nie Codewerte.
 *
 * Status im Ausgang: pending (wartet) -> sending (beansprucht, claimed_at) -> done | pending (nächster Versuch) | failed.
 */
class CommerceEvent extends Model
{
    use HasUuids;

    protected $table = 'commerce_event';

    public $timestamps = false;

    protected $fillable = [
        'direction', 'type', 'shop_account_id', 'tenant_id', 'correlation_id', 'payload',
        'status', 'attempts', 'last_error', 'available_at', 'claimed_at', 'created_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'available_at' => 'datetime', 'claimed_at' => 'datetime', 'created_at' => 'datetime', 'processed_at' => 'datetime', 'attempts' => 'integer'];
    }

    /** Eingehenden Aufruf protokollieren (ohne Codewerte). */
    public static function logIncoming(string $type, ?CommerceShopAccount $account, ?string $correlationId, array $payload, ?string $tenantId = null): self
    {
        return static::create([
            'direction' => 'in',
            'type' => $type,
            'shop_account_id' => $account?->id,
            'tenant_id' => $tenantId ?? $account?->tenant_id,
            'correlation_id' => $correlationId ? substr($correlationId, 0, 64) : null,
            'payload' => $payload,
            'status' => 'done',
            'created_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
