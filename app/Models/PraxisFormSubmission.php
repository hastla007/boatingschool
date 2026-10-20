<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PraxisFormSubmission extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'praxis_form_submission';

    protected $fillable = [
        'tenant_id', 'course_id', 'user_id', 'praxis_form_template_id', 'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PraxisFormTemplate::class, 'praxis_form_template_id');
    }
}
