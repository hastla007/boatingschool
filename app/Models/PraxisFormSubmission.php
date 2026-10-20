<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PraxisFormSubmission extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'praxis_form_submission';

    protected $fillable = [
        'tenant_id', 'course_id', 'user_id', 'praxis_form_template_id', 'data', 'confirmed_exam_date',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'confirmed_exam_date' => 'datetime',
        ];
    }

    public function examAppointments(): HasMany
    {
        return $this->hasMany(PraxisExamAppointment::class)->orderBy('round');
    }

    public function latestExamAppointment(): ?PraxisExamAppointment
    {
        return $this->examAppointments->last();
    }

    public function isExamDateConfirmed(): bool
    {
        return $this->confirmed_exam_date !== null;
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
