<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CourseAccessRule extends Model
{
    use HasUuids;

    protected $table = 'course_access_rule';

    protected $fillable = [
        'tenant_id', 'course_id', 'feature_code', 'access_type', 'module_code',
        'limit_value', 'valid_from', 'valid_until', 'source_key',
    ];

    protected function casts(): array
    {
        return [
            'limit_value' => 'integer',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }
}
