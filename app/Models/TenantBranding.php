<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBranding extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_branding';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'tenant_id', 'logo_asset_id', 'favicon_asset_id', 'primary_color', 'secondary_color',
        'support_email', 'support_email_verified_at', 'legal_name', 'contact_first_name', 'contact_last_name',
        'imprint_url', 'privacy_url', 'custom_domain', 'phone', 'street', 'postal_code', 'city', 'country',
        'website', 'exam_readiness_threshold_percent', 'whatsapp_enabled', 'whatsapp_phone', 'whatsapp_greeting',
    ];

    protected function casts(): array
    {
        return [
            'support_email_verified_at' => 'datetime',
            'whatsapp_enabled' => 'boolean',
        ];
    }

    public function hasVerifiedSupportEmail(): bool
    {
        return ! $this->support_email || $this->support_email_verified_at !== null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function logoAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'logo_asset_id');
    }
}
