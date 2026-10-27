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
        'support_email', 'support_email_verified_at', 'email_support_enabled', 'legal_name',
        'contact_first_name', 'contact_last_name', 'imprint_url', 'privacy_url', 'custom_domain',
        'phone', 'phone_support_enabled', 'street', 'postal_code', 'city', 'country',
        'website', 'exam_readiness_threshold_percent', 'willkommensbox_threshold_percent', 'whatsapp_enabled', 'whatsapp_phone', 'whatsapp_greeting',
        'label_size_a4', 'label_size_a5', 'label_size_a6',
        'course_validity_days', 'renewal_webshop_link',
    ];

    protected function casts(): array
    {
        return [
            'support_email_verified_at' => 'datetime',
            'email_support_enabled' => 'boolean',
            'phone_support_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'label_size_a4' => 'boolean',
            'label_size_a5' => 'boolean',
            'label_size_a6' => 'boolean',
        ];
    }

    /** @return array<int, string> z. B. ['a4', 'a6'] -- Reihenfolge wie in der Branding-UI. */
    public function enabledLabelSizes(): array
    {
        return collect(['a4' => $this->label_size_a4, 'a5' => $this->label_size_a5, 'a6' => $this->label_size_a6])
            ->filter()
            ->keys()
            ->all();
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

    public function faviconAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'favicon_asset_id');
    }

    /**
     * Bug-Review Oberfläche U4: primary_color/secondary_color wurden bisher
     * nirgends gelesen -- jede Nutzung von var(--brand-primary, #005FD7) im
     * Markup griff dadurch immer nur auf den hartkodierten Fallback zurück,
     * unabhängig vom in der Bootsschul-Admin-Oberfläche eingestellten Wert.
     * Ein nicht valider Wert (z. B. aus einer alten, manuell geänderten
     * Zeile) würde unvalidiert in ein <style>-Tag interpoliert sonst CSS/
     * Markup-Injection ermöglichen -- hier zusätzlich zur Validierung bei
     * der Eingabe (BrandingController) nochmals gegen das erwartete
     * #rrggbb-Format geprüft, bevor der Wert ausgegeben wird.
     */
    public function primaryColorOrDefault(): string
    {
        return $this->isValidHexColor($this->primary_color) ? $this->primary_color : '#005FD7';
    }

    /**
     * Bug-Review Oberfläche U6: #00A8A8 (der bisherige Standard- und
     * Neuanlage-Wert, siehe Superadmin\TenantController::store() und
     * TenantSeeder) erreicht mit weißer Buttonschrift nur 2,9:1 -- WCAG AA
     * verlangt 4,5:1 für Text. #007A7A (dieselbe Farbfamilie, nur dunkler/
     * gesättigter) kommt auf rechnerisch 5,2:1 und besteht damit mit
     * Spielraum.
     */
    public function secondaryColorOrDefault(): string
    {
        return $this->isValidHexColor($this->secondary_color) ? $this->secondary_color : '#007A7A';
    }

    private function isValidHexColor(?string $value): bool
    {
        return $value !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
