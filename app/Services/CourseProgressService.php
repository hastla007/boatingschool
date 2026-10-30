<?php

namespace App\Services;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Module;
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
        return (int) round($this->exactPercent($course, $tenant, $user));
    }

    /**
     * Wie overallPercent(), aber ungerundet -- für Gates (Praxisformular-
     * Freischaltung, Willkommensbox-Schwelle, Addon-/Banner-Trigger) muss
     * der TATSÄCHLICHE Fortschritt gegen die Schwelle verglichen werden.
     *
     * Bug-Review (Gates vergleichen den gerundeten Wert): overallPercent()
     * rundet für die ANZEIGE auf eine ganze Zahl -- 49,5% gefestigte
     * Fragen werden dabei zu "50%" aufgerundet. Verglichen Gates bisher
     * gegen diesen bereits gerundeten Wert, schaltete das einen Gate mit
     * Schwelle 50% frei, obwohl tatsächlich weniger als die Hälfte der
     * Fragen gefestigt war.
     */
    public function exactPercent(CourseDefinition $course, Tenant $tenant, User $user): float
    {
        $course->loadMissing('modules.questions');

        // Bug-Review F7: eine vom Superadmin deaktivierte Frage (z. B.
        // fehlerhaft oder veraltet) taucht im Smarttrainer, im
        // Favoriten-Fragenpool und in der Prüfung nicht mehr auf (siehe
        // SmarttrainerService, FavoriteController, ExamController) -- sie
        // zählte hier aber weiter im Nenner mit. Da sie so nie "gefestigt"
        // werden konnte, waren 100% Kursfortschritt dauerhaft unerreichbar,
        // und die darauf aufbauenden Freigaben (Praxisformular,
        // Willkommensbox, Addon-/Banner-Empfehlungen) blieben gesperrt.
        // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
        $questionIds = ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions));
        $total = max($questionIds->count(), 1);

        $mastered = Progress::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->whereIn('question_id', $questionIds)
            ->where('learning_state', 'gefestigt')
            ->count();

        return $mastered / $total * 100;
    }

    /**
     * Fortschritt je Modul, weiter aufgeschlüsselt nach der echten
     * Smart-Learning-Kategorie (smartmodus_kategorie, ersatzweise topic) --
     * dieselbe Gruppierung wie auf der Smart-Learning-Übersicht, damit
     * andere Seiten (z. B. die Kursübersicht) direkt dorthin verlinken
     * können, ohne die Aufschlüsselung zu duplizieren.
     *
     * @return Collection<int, array{module: Module, topics: Collection, total: int, mastered: int, percent: int}>
     */
    public function moduleKategorieBreakdown(CourseDefinition $course, Tenant $tenant, User $user): Collection
    {
        $course->loadMissing(['modules.questions.revisions' => function ($query) {
            $query->where('editorial_status', 'published')->orderByDesc('revision_no');
        }]);

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $mastered = fn ($questionIds) => $progress->whereIn('question_id', $questionIds)->where('learning_state', 'gefestigt')->count();

        return $course->modules->map(function ($module) use ($mastered) {
            // Bug-Review F7: siehe overallPercent() -- eine deaktivierte
            // Frage zählte hier ebenfalls weiter mit, obwohl sie nie
            // "gefestigt" werden kann.
            // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
            $activeQuestions = ContentQuestion::filterCurrentlyValid($module->questions);

            $topics = $activeQuestions
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

            $questionIds = $activeQuestions->pluck('id');
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
