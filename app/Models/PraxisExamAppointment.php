<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PraxisExamAppointment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'praxis_exam_appointment';

    protected $fillable = [
        'tenant_id', 'praxis_form_submission_id', 'round', 'exam_date',
        'proposed_by_user_id', 'response', 'response_comment', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->response === 'pending';
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(PraxisFormSubmission::class, 'praxis_form_submission_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }
}
