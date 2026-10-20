<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * Alle Terminvorschlagsrunden über alle Slots hinweg -- slot 0 ist der
     * Prüfungstermin selbst, slot 1, 2, ... sind unabhängige, zusätzliche
     * Praxistermine (siehe requiredPracticeAppointmentCount()). Für einen
     * einzelnen Slot immer appointmentsForSlot() verwenden, nicht direkt
     * filtern.
     */
    public function examAppointments(): HasMany
    {
        return $this->hasMany(PraxisExamAppointment::class)->orderBy('slot')->orderBy('round');
    }

    /** @return Collection<int, PraxisExamAppointment> */
    public function appointmentsForSlot(int $slot): Collection
    {
        return $this->examAppointments->where('slot', $slot)->values();
    }

    public function latestAppointmentForSlot(int $slot): ?PraxisExamAppointment
    {
        return $this->appointmentsForSlot($slot)->last();
    }

    public function latestExamAppointment(): ?PraxisExamAppointment
    {
        return $this->latestAppointmentForSlot(0);
    }

    public function isExamDateConfirmed(): bool
    {
        return $this->confirmed_exam_date !== null;
    }

    /** Anzahl zusätzlicher Praxistermine, die für den Kurs dieser Anmeldung vorgesehen sind (siehe PraxisFormTemplate). */
    public function requiredPracticeAppointmentCount(): int
    {
        return $this->template->practice_appointment_count ?? 0;
    }

    public function isPracticeAppointmentConfirmed(int $slot): bool
    {
        return $this->confirmedPracticeDate($slot) !== null;
    }

    public function confirmedPracticeDate(int $slot): ?Carbon
    {
        return $this->appointmentsForSlot($slot)->firstWhere('response', 'accepted')?->exam_date;
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
