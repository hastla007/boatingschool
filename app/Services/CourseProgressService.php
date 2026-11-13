<?php

namespace App\Services;

use App\Models\ContentQuestion;
use App\Models\CourseDefinition;
use App\Models\Module;
use App\Models\Progress;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
     * Die eine Formel für den Kursfortschritt: Anteil gefestigter an den
     * aktuell gültigen Fragen des Kurses, gerundet auf ganze Prozent. Ein
     * Kurs ohne gültige Fragen zählt mit Nenner 1 (also 0 %).
     */
    public static function percentOf(int $mastered, int $validQuestionCount): int
    {
        return (int) round($mastered / max($validQuestionCount, 1) * 100);
    }

    /**
     * Aktuell gültige Fragen-IDs eines Kurses (siehe Bug-Review F7/BB16 in
     * exactPercent()).
     *
     * @return Collection<int, string>
     */
    public function validQuestionIds(CourseDefinition $course): Collection
    {
        return $this->validQuestionIdsByCourse(collect([$course]))[$course->id];
    }

    /**
     * Aktuell gültige Fragen-IDs für mehrere Kurse auf einmal: Module/Fragen
     * werden gemeinsam geladen und die Gültigkeit aller Fragen mit EINER
     * Revisions-Abfrage geprüft (statt einer je Kurs).
     *
     * @param  Collection<int, CourseDefinition>  $courses
     * @return array<string, Collection<int, string>> course_id => Fragen-IDs
     */
    public function validQuestionIdsByCourse(Collection $courses): array
    {
        $courses = EloquentCollection::make($courses->filter()->unique('id')->values());
        if ($courses->isEmpty()) {
            return [];
        }
        $courses->loadMissing('modules.questions');
        $questionsOf = fn (CourseDefinition $course) => $course->modules->flatMap(fn ($m) => $m->questions);
        $valid = array_flip(ContentQuestion::currentlyValidIds($courses->flatMap($questionsOf))->all());

        return $courses->mapWithKeys(fn (CourseDefinition $course) => [
            $course->id => $questionsOf($course)->pluck('id')->unique()->filter(fn ($id) => isset($valid[$id]))->values(),
        ])->all();
    }

    /**
     * Kursfortschritt (wie overallPercent()) für viele Lernende und Kurse
     * einer Bootsschule auf einmal. Gezählt wird in SQL (GROUP BY Lerner und
     * Kurs, verknüpft mit den gültigen Fragen-IDs je Kurs) -- Speicher und
     * Laufzeit wachsen damit nicht mehr mit Lernenden × gefestigten Fragen,
     * und die Zahl der Abfragen hängt weder von der Zahl der Lernenden noch
     * der Kurse ab. Für Dashboard, Statistik, Exporte und Superadmin-Seiten.
     *
     * @param  Collection<int, CourseDefinition>  $courses
     * @param  iterable<string>  $userIds
     * @return array<string, array<string, int>> user_id => course_id => Prozent (für jede Kombination)
     */
    public function percentsByUserAndCourse(Tenant|string $tenant, Collection $courses, iterable $userIds): array
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $userIds = collect($userIds)->filter()->unique()->values();
        $validIdsByCourse = $this->validQuestionIdsByCourse($courses);
        if ($userIds->isEmpty() || $validIdsByCourse === []) {
            return [];
        }

        // Paare (Kurs, Frage) als zwei parallele Array-Parameter -- ein
        // Platzhalter je ID würde bei großen Kursen/Schulen an Postgres'
        // Grenze von 65.535 Parametern stoßen.
        $pairCourseIds = [];
        $pairQuestionIds = [];
        foreach ($validIdsByCourse as $courseId => $questionIds) {
            foreach ($questionIds as $questionId) {
                $pairCourseIds[] = $courseId;
                $pairQuestionIds[] = $questionId;
            }
        }

        $mastered = [];
        if ($pairQuestionIds !== []) {
            $connection = (new Progress)->getConnection();
            foreach ($userIds->chunk(5000) as $chunk) {
                $rows = $connection->select(
                    "select p.user_id, v.course_id, count(*) as mastered
                       from progress p
                       join unnest(?::uuid[], ?::uuid[]) as v(course_id, question_id) on v.question_id = p.question_id
                      where p.tenant_id = ? and p.user_id = any(?::uuid[]) and p.learning_state = 'gefestigt'
                      group by p.user_id, v.course_id",
                    [self::uuidArray($pairCourseIds), self::uuidArray($pairQuestionIds), $tenantId, self::uuidArray($chunk->all())]
                );
                foreach ($rows as $row) {
                    $mastered[$row->user_id][$row->course_id] = (int) $row->mastered;
                }
            }
        }

        $result = [];
        foreach ($userIds as $userId) {
            foreach ($validIdsByCourse as $courseId => $questionIds) {
                $result[$userId][$courseId] = self::percentOf($mastered[$userId][$courseId] ?? 0, $questionIds->count());
            }
        }

        return $result;
    }

    /**
     * overallPercent() für viele Lernende eines Kurses auf einmal -- für
     * Statistik und Exporte.
     *
     * @param  iterable<string>  $userIds
     * @return array<string, int> user_id => Prozent
     */
    public function overallPercents(CourseDefinition $course, Tenant $tenant, iterable $userIds): array
    {
        return collect($this->percentsByUserAndCourse($tenant, collect([$course]), $userIds))
            ->map(fn (array $byCourse) => $byCourse[$course->id])->all();
    }

    /** Postgres-Array-Literal aus UUIDs (aus der Datenbank, daher ohne Quoting). */
    private static function uuidArray(array $ids): string
    {
        return '{'.implode(',', $ids).'}';
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
        // Bug-Review F7: eine vom Superadmin deaktivierte Frage (z. B.
        // fehlerhaft oder veraltet) taucht im Smarttrainer, im
        // Favoriten-Fragenpool und in der Prüfung nicht mehr auf (siehe
        // SmarttrainerService, FavoriteController, ExamController) -- sie
        // zählte hier aber weiter im Nenner mit. Da sie so nie "gefestigt"
        // werden konnte, waren 100% Kursfortschritt dauerhaft unerreichbar,
        // und die darauf aufbauenden Freigaben (Praxisformular,
        // Willkommensbox, Addon-/Banner-Empfehlungen) blieben gesperrt.
        // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
        $questionIds = $this->validQuestionIds($course);
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
