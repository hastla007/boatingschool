<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    use HasUuids;

    protected $table = 'coupon';

    public $timestamps = false;

    protected $fillable = [
        'code', 'course_id', 'product_id', 'tenant_id', 'batch_label', 'created_by_user_id',
        'redeemed_by_user_id', 'redeemed_tenant_id', 'redeemed_at',
    ];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** Anzeigename des durch diesen Coupon freigeschalteten Kurses oder Produkts. */
    public function redeemableName(): string
    {
        return $this->course?->name ?? $this->product?->name ?? '—';
    }

    public function isForProduct(): bool
    {
        return $this->product_id !== null;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    public function redeemedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'redeemed_tenant_id');
    }

    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    public static function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(implode('-', [
                substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4),
                substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4),
                substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 4),
            ]));
        } while (static::withoutGlobalScopes()->where('code', $code)->exists());

        return $code;
    }
}
