<?php

namespace App\Services;

use App\Models\CourseDefinition;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;

/**
 * Gesamt-Kursfortschritt (Anteil "gefestigter" Fragen) eines Lerners --
 * dieselbe Kennzahl, die auf der Kursübersicht als Fortschrittsring
 * angezeigt wird, hier als eigener Service, damit auch Gates wie die
 * Buchungsvoraussetzung für "Praxis & Prüfung" sie ohne Duplikation nutzen
 * können.
 */
class CourseProgressService
{
    public function overallPercent(CourseDefinition $course, Tenant $tenant, User $user): int
    {
        $course->loadMissing('modules.questions');

        $questionIds = $course->modules->flatMap(fn ($m) => $m->questions)->pluck('id')->unique();
        $total = max($questionIds->count(), 1);

        $mastered = Progress::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('question_id', $questionIds)
            ->where('learning_state', 'gefestigt')
            ->count();

        return (int) round($mastered / $total * 100);
    }
}
