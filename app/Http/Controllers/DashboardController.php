<?php

namespace App\Http\Controllers;

use App\Models\ContentQuestion;
use App\Models\Progress;
use App\Models\VideoProgress;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use App\Support\VideoCourseLessons;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /** Ab dieser Smart-Learning-Quote schlägt der Weiterlernen-Banner die Prüfungssimulation statt des Smarttrainers vor. */
    private const EXAM_READY_PERCENT = 90;

    public function __invoke(Request $request, TenantContext $tenantContext, EntitlementService $entitlements): View
    {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        $courses = $entitlements->activeCourses($tenant, $user);

        $progressStats = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $videoProgressStats = VideoProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('completed', true)->get()->keyBy('video_lesson_id');

        // Jeder gebuchte Kurs bekommt auf dem Dashboard eine eigene Karte
        // (siehe dashboard.blade.php) -- daher hier je Kurs die vollen
        // Statistiken statt nur des einen "aktivsten" Kurses, den es vor
        // Einführung mehrerer Kurskarten gab.
        $courseSummaries = $courses->map(function ($course) use ($progressStats, $videoProgressStats) {
            // Bug-Review (Fortschritt zählt deaktivierte Fragen): eine
            // vom Superadmin deaktivierte Frage kann nie "gefestigt"
            // werden (siehe CourseProgressService::overallPercent, Bug-
            // Review F7), zählte hier aber weiter im Nenner mit -- diese
            // eigene, von CourseProgressService losgelöste Berechnung
            // (für die Weiterlernen-Banner-Logik unten) hatte den F7-Fix
            // nie mitbekommen.
            // Bug-Review BB16: dieselbe Lücke bestand für valid_from/valid_to.
            $questionIds = ContentQuestion::currentlyValidIds($course->modules->flatMap(fn ($m) => $m->questions));
            $courseProgress = $progressStats->whereIn('question_id', $questionIds);
            $mastered = $courseProgress->where('learning_state', 'gefestigt')->count();
            $total = max($questionIds->count(), 1);
            $percent = (int) round(($mastered / $total) * 100);

            // Weiterlernen-Banner führt je nach Fortschritt zu einem anderen
            // nächsten Schritt: erst der Videokurs (falls vorhanden), dann der
            // Smarttrainer, ab hoher Mastery-Quote schließlich die Prüfungssimulation.
            $videoLessons = VideoCourseLessons::ordered($course);
            $videoComplete = $videoLessons->isEmpty()
                || $videoLessons->every(fn ($lesson) => $videoProgressStats->has($lesson->id));

            // Bug-Review (BB4: Dashboard empfiehlt Prüfung ohne
            // Prüfungsbögen): wie ExamController::intro() -- ein Kurs hat
            // nur dann tatsächlich eine Prüfungssimulation, wenn er feste
            // Prüfungsbögen hat ODER ein fachlich freigegebenes Regelwerk
            // für die zufällig zusammengestellte Prüfung vorliegt. Ohne
            // beides landete der "Prüfungsaufgaben"-Banner auf einer
            // exam.intro-Seite, die nur "noch kein Regelwerk verfügbar"
            // zeigte -- eine Sackgasse statt des versprochenen nächsten
            // Schritts.
            //
            // Bug-Review (Folgefehler BB4): "hat Prüfungsbögen" allein
            // reichte nicht -- ein Kurs mit Bögen, aber nur einem Entwurfs-
            // Regelwerk (z. B. SBF-BIN-SEGEL), zeigte auf der Bogenübersicht
            // keinen einzigen startbaren Bogen. hasStartableExam() prüft wie
            // ExamController (startPaper()/papersOverview()) das freigegebene
            // Regelwerk des Kurses bzw. bei Kombikursen das des Teilkurses.
            $examAvailable = $course->hasStartableExam();

            $bannerMode = match (true) {
                ! $videoComplete => 'video',
                $percent > self::EXAM_READY_PERCENT && $examAvailable => 'exam',
                default => 'smart',
            };

            return [
                'course' => $course,
                'total_questions' => $questionIds->count(),
                'answered' => $courseProgress->where('attempt_count', '>', 0)->count(),
                'mastered' => $mastered,
                'percent' => $percent,
                'correct' => $courseProgress->sum('correct_count'),
                'incorrect' => $courseProgress->sum('incorrect_count'),
                'attempts' => $courseProgress->sum('attempt_count'),
                'banner_mode' => $bannerMode,
            ];
        });

        return view('dashboard', [
            'courses' => $courseSummaries,
        ]);
    }
}
