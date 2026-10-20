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
        'tenant_id', 'addon_template_id', 'active', 'coupon_code', 'price_label',
        'trigger_course_id', 'trigger_percent', 'offer_url',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'trigger_percent' => 'integer',
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
}
