<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantCourseLocation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'tenant_course_location';

    protected $fillable = [
        'tenant_id', 'praxis_ort', 'pruefung_ort', 'course_ids', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'course_ids' => 'array',
            'sort_order' => 'integer',
        ];
    }
}
