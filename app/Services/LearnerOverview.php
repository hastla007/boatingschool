<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\CourseDefinition;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\PraxisFormTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lernstand von Teilnehmern für den Bootsschul-Admin: Fortschritt je
 * gebuchtem Kurs, Prüfungsreife (Schwelle wie auf der Kursseite: aus der
 * Praxis-Anmeldevorlage des Kurses, sonst aus dem Branding), letzte
 * Aktivität und Ergebnisse der Prüfungssimulation.
 */
class LearnerOverview
{
    public function __construct(private CourseProgressService $progress) {}

    public function threshold(Tenant $tenant, CourseDefinition $course): int
    {
        return (int) (PraxisFormTemplate::where('active', true)->whereJsonContains('course_ids', $course->id)->value('min_progress_percent')
            ?? $tenant->branding?->exam_readiness_threshold_percent ?? 80);
    }

    /** @return list<array{course: CourseDefinition, percent: int, threshold: int, ready: bool, valid_until: ?Carbon, status: string}> */
    public function courses(Tenant $tenant, User $user): array
    {
        return Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)->with('course')->get()
            ->filter(fn (Entitlement $e) => $e->course !== null)
            ->map(function (Entitlement $e) use ($tenant, $user) {
                $percent = $this->progress->overallPercent($e->course, $tenant, $user);
                $threshold = $this->threshold($tenant, $e->course);

                return [
                    'course' => $e->course,
                    'percent' => $percent,
                    'threshold' => $threshold,
                    'ready' => $percent >= $threshold,
                    'valid_until' => $e->valid_until,
                    'status' => $e->status,
                ];
            })->values()->all();
    }

    public function lastActivity(Tenant $tenant, User $user): ?Carbon
    {
        $value = Attempt::where('tenant_id', $tenant->id)->where('user_id', $user->id)->max('created_at');

        return $value ? Carbon::parse($value) : null;
    }

    /** @return Collection<int, string|null> letzte Aktivität je Nutzer-ID (ein Query) */
    public function lastActivities(Tenant $tenant, Collection $userIds): Collection
    {
        return Attempt::where('tenant_id', $tenant->id)->whereIn('user_id', $userIds)
            ->groupBy('user_id')->selectRaw('user_id, max(created_at) as last_at')->pluck('last_at', 'user_id');
    }

    /** @return Collection<int, ExamSession> */
    public function examResults(Tenant $tenant, User $user, int $limit = 10): Collection
    {
        return ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'evaluated')
            ->with('course:id,name')->orderByDesc('submitted_at')->limit($limit)->get();
    }
}
