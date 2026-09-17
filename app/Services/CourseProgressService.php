<?php

namespace App\Services;

use App\Models\CourseDefinition;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

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

    /**
     * Fortschritt je Modul, weiter aufgeschlüsselt nach der echten
     * Smart-Learning-Kategorie (smartmodus_kategorie, ersatzweise topic) --
     * dieselbe Gruppierung wie auf der Smart-Learning-Übersicht, damit
     * andere Seiten (z. B. die Kursübersicht) direkt dorthin verlinken
     * können, ohne die Aufschlüsselung zu duplizieren.
     *
     * @return Collection<int, array{module: \App\Models\Module, topics: Collection, total: int, mastered: int, percent: int}>
     */
    public function moduleKategorieBreakdown(CourseDefinition $course, Tenant $tenant, User $user): Collection
    {
        $course->loadMissing(['modules.questions.revisions' => function ($query) {
            $query->where('editorial_status', 'published')->orderByDesc('revision_no');
        }]);

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $mastered = fn ($questionIds) => $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();

        return $course->modules->map(function ($module) use ($mastered) {
            $topics = $module->questions
                ->groupBy(function ($q) {
                    $revision = $q->revisions->first();

                    return $revision?->smartmodus_kategorie ?: ($revision?->topic ?: 'Sonstiges');
                })
                ->map(function ($questions, $topic) use ($mastered) {
                    $questionIds = $questions->pluck('id');
                    $total = $questionIds->count();
                    $topicMastered = $mastered($questionIds);

                    return [
                        'topic' => $topic,
                        'total' => $total,
                        'mastered' => $topicMastered,
                        'percent' => $total > 0 ? (int) round($topicMastered / $total * 100) : 0,
                    ];
                })
                ->sortByDesc('total')
                ->values();

            $questionIds = $module->questions->pluck('id');
            $total = $questionIds->count();
            $moduleMastered = $mastered($questionIds);

            return [
                'module' => $module,
                'topics' => $topics,
                'total' => $total,
                'mastered' => $moduleMastered,
                'percent' => $total > 0 ? (int) round($moduleMastered / $total * 100) : 0,
            ];
        })->values();
    }
}
