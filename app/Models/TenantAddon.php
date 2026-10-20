<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantAddon extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    protected $table = 'tenant_addon';

    protected array $compositeKey = ['tenant_id', 'addon_template_id'];

    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'addon_template_id', 'active', 'coupon_code', 'price_label', 'discount_percent',
        'trigger_course_id', 'trigger_percent', 'offer_url',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'trigger_percent' => 'integer',
            'discount_percent' => 'integer',
        ];
    }

    public function addonTemplate(): BelongsTo
    {
        return $this->belongsTo(AddonTemplate::class, 'addon_template_id');
    }

    public function triggerCourse(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'trigger_course_id');
    }

    /**
     * Rabattierter VK, berechnet aus dem frei eingegebenen price_label
     * (z. B. "299 €") und discount_percent -- null, wenn kein Rabatt
     * hinterlegt ist oder sich aus price_label keine Zahl extrahieren
     * lässt (price_label bleibt bewusst ein Freitextfeld).
     */
    public function discountedPriceLabel(): ?string
    {
        if (! $this->discount_percent || ! $this->price_label) {
            return null;
        }

        $amount = $this->parsePriceAmount($this->price_label);

        if ($amount === null) {
            return null;
        }

        $discounted = round($amount * (1 - $this->discount_percent / 100), 2);

        return number_format($discounted, 2, ',', '.').' €';
    }

    private function parsePriceAmount(string $label): ?float
    {
        if (! preg_match('/[\d.,]+/', $label, $matches)) {
            return null;
        }

        $number = $matches[0];

        if (str_contains($number, ',') && str_contains($number, '.')) {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);
        } elseif (str_contains($number, ',')) {
            $number = str_replace(',', '.', $number);
        }

        return is_numeric($number) ? (float) $number : null;
    }
}
