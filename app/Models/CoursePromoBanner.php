<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use App\Models\Concerns\HasDiscountedPriceLabel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoursePromoBanner extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey, HasDiscountedPriceLabel;

    protected $table = 'course_promo_banner';

    protected array $compositeKey = ['tenant_id', 'course_id'];

    public $incrementing = false;

    protected $fillable = [
        'tenant_id', 'course_id', 'active', 'headline', 'description', 'cta_label',
        'price_label', 'discount_percent', 'coupon_code', 'offer_url',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'discount_percent' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }
}
