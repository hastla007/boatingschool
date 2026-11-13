<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kundenkonto des B2B-Shops und seine Verknüpfung mit App-Konto und Schule
 * (docs/commerce/README.md). Plattformweit, ohne RLS.
 */
class CommerceShopAccount extends Model
{
    use HasUuids;

    public const PENDING_REVIEW = 'pending_review';

    public const AWAITING_LINK = 'awaiting_link';

    public const LINKED = 'linked';

    public const REJECTED = 'rejected';

    public const REVOKED = 'revoked';

    protected $table = 'commerce_shop_account';

    protected $fillable = [
        'shop_id', 'shop_customer_id', 'user_id', 'tenant_id', 'status',
        'email', 'contact_name', 'company_name', 'billing_country', 'vat_id', 'shop_data_updated_at',
        'provisioning_status', 'provisioning_error',
        'reviewed_by_user_id', 'reviewed_at', 'review_note', 'linked_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'shop_data_updated_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'linked_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Darf über dieses Konto für die Schule eingekauft werden? Live geprüft:
     * verknüpft, Schule aktiv, Person noch Inhaber/Admin der Schule.
     */
    public function canPurchase(): bool
    {
        if ($this->status !== self::LINKED || ! $this->tenant_id || ! $this->user_id) {
            return false;
        }
        $tenant = Tenant::on('pgsql_admin')->find($this->tenant_id);
        $user = User::on('pgsql_admin')->find($this->user_id);
        if (! $tenant || ! in_array($tenant->status, ['active', 'trial'], true) || ! $user || ! in_array($user->status, ['active', 'invited'], true)) {
            return false;
        }

        return TenantUser::on('pgsql_admin')->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'admin'])->where('status', 'active')->exists();
    }
}
