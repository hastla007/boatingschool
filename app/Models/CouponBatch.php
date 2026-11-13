<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Code-Batch einer bezahlten Shop-Bestellposition (genau einer je Position). */
class CouponBatch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'coupon_batch';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'kind', 'course_id', 'product_id', 'option_product_ids', 'quantity', 'source',
        'shop_id', 'order_id', 'order_line_id', 'order_number', 'shop_account_id', 'request_fingerprint',
        'status', 'void_reason', 'voided_at', 'created_at',
    ];

    protected function casts(): array
    {
        return ['option_product_ids' => 'array', 'voided_at' => 'datetime', 'created_at' => 'datetime', 'quantity' => 'integer'];
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class, 'batch_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
