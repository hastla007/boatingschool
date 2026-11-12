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
    /**
     * overallPercent() für viele Lernende eines Kurses auf einmal (zwei
     * Abfragen statt zwei je Lerner) -- für Statistik und Exporte.
     *
     * @param  iterable<string>  $userIds
     * @return array<string, int> user_id => Prozent
     */
    public function overallPercents(CourseDefinition $course, Tenant $tenant, iterable $userIds): array
    {
        $userIds = collect($userIds)->unique()->values();
        if ($userIds->isEmpty()) {
            return [];
        }
        $course->loadMissing('modules.questions');
        $questionIds = ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions));
        $total = max($questionIds->count(), 1);

        $mastered = [];
        foreach ($userIds->chunk(2000) as $chunk) {
            $mastered += Progress::where('tenant_id', $tenant->id)
                ->whereIn('user_id', $chunk->all())
                ->whereIn('question_id', $questionIds)
                ->where('learning_state', 'gefestigt')
                ->groupBy('user_id')
                ->selectRaw('user_id, count(*) as mastered')
                ->pluck('mastered', 'user_id')->all();
        }

        return $userIds->mapWithKeys(fn ($id) => [$id => (int) round(($mastered[$id] ?? 0) / $total * 100)])->all();
    }

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
    public function moduleKategorieBreakdown(CourseDefinition $course, Tenant $tenant, User $user, ?Collection $allowedQuestionIds = null): Collection
    {
        $course->loadMissing(['modules.questions.revisions' => function ($query) {
            $query->where('editorial_status', 'published')->orderByDesc('revision_no');
        }]);

        $progress = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get()->keyBy('question_id');
        $summary = function ($questionIds) use ($progress): array {
            $questionIds = collect($questionIds)->unique()->values();
            $states = $questionIds->map(fn ($id) => $progress->get($id)?->learning_state ?? 'neu');
            $mastered = $states->filter(fn ($state) => $state === 'gefestigt')->count();
            $unsure = $states->filter(fn ($state) => $state === 'unsicher')->count();
            $learning = $states->filter(fn ($state) => $state === 'in_bearbeitung')->count();

            return [
                'total' => $questionIds->count(),
                'mastered' => $mastered,
                'unsure' => $unsure,
                'learning' => $learning,
                'new' => max(0, $questionIds->count() - $mastered - $unsure - $learning),
                'percent' => $questionIds->isNotEmpty() ? (int) floor($mastered * 100 / $questionIds->count()) : 0,
            ];
        };

        return $course->modules->map(function ($module) use ($allowedQuestionIds, $summary) {
            $activeQuestions = ContentQuestion::filterCurrentlyValid($module->questions);
            if ($allowedQuestionIds !== null) {
                $activeQuestions = $activeQuestions->whereIn('id', $allowedQuestionIds);
            }

            $topicGroups = $activeQuestions->groupBy(function ($question) use ($module) {
                $revision = $question->revisions->first();
                $topic = trim((string) ($revision?->smartmodus_kategorie ?: $revision?->topic ?: ''));
                return $topic !== '' && $topic !== $module->name ? $topic : null;
            })->reject(fn ($questions, $topic) => $topic === null || $topic === '');
            $topics = $topicGroups->count() < 2 ? collect() : $topicGroups->map(function ($questions, $topic) use ($summary) {
                return ['topic' => $topic] + $summary($questions->pluck('id'));
            })->sort(function ($a, $b) {
                return $b['total'] <=> $a['total'] ?: strcmp((string) $a['topic'], (string) $b['topic']);
            })->values();

            return ['module' => $module, 'topics' => $topics] + $summary($activeQuestions->pluck('id'));
        })->values();
    }
}
