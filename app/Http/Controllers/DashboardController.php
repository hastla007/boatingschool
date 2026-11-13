<?php

namespace App\Http\Controllers;

use App\Models\ContentQuestion;
use App\Models\Entitlement;
use App\Models\ExamSession;
use App\Models\Favorite;
use App\Models\PlatformAnnouncement;
use App\Models\Progress;
use App\Models\TenantAnnouncement;
use App\Models\VideoProgress;
use App\Services\EntitlementService;
use App\Support\TenantContext;
use App\Support\VideoCourseLessons;
use Illuminate\Database\Eloquent\Collection;
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

        $activeEntitlements = $entitlements->activeEntitlements($tenant, $user);
        $courses = $activeEntitlements->map(fn (Entitlement $e) => $e->course)->unique('id')->values();
        // Review-Runde 11: Fragen aller Kurse in einer Abfrage statt je Modul.
        (new Collection($courses->all()))->loadMissing('modules.questions');

        // Review-Runde 11: bald ablaufende und schon abgelaufene Kurse nicht
        // stillschweigend verschwinden lassen.
        $expiringSoon = $activeEntitlements->filter->expiresSoon()
            ->reject(fn (Entitlement $e) => $activeEntitlements->contains(fn ($o) => $o->course_id === $e->course_id && ($o->valid_until === null || $o->valid_until > $e->valid_until)))
            ->unique('course_id')->values();
        $expiredCourseNames = Entitlement::with('course')->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('status', ['active', 'expired'])->whereNotNull('valid_until')->where('valid_until', '<=', now())
            ->whereNotIn('course_id', $courses->pluck('id'))->get()
            ->pluck('course.name')->filter()->unique()->values();

        $progressStats = Progress::where('tenant_id', $tenant->id)->where('user_id', $user->id)->get();
        $videoProgressStats = VideoProgress::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('completed', true)->get()->keyBy('video_lesson_id');

        // Jeder gebuchte Kurs bekommt auf dem Dashboard eine eigene Karte
        // (siehe dashboard.blade.php) -- daher hier je Kurs die vollen
        // Statistiken statt nur des einen "aktivsten" Kurses, den es vor
        // Einführung mehrerer Kurskarten gab.
        // Leere Kachel „Favoriten & Fehler“ erkennen (Hinweis statt Kursname):
        // keine Favoriten, keine falschen Antworten, keine Prüfungssimulation.
        $favoriteQuestionIds = Favorite::where('tenant_id', $tenant->id)->where('user_id', $user->id)->pluck('question_id');
        $examCourseIds = ExamSession::where('tenant_id', $tenant->id)->where('user_id', $user->id)->distinct()->pluck('course_id');

        $courseSummaries = $courses->map(function ($course) use ($progressStats, $videoProgressStats, $favoriteQuestionIds, $examCourseIds) {
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
            // Platzhalter („Video folgt“) lassen sich nicht abschließen und
            // halten den Banner deshalb nicht beim Videokurs fest.
            $videoLessons = VideoCourseLessons::countable(VideoCourseLessons::ordered($course));
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
                'video_started' => $videoLessons->contains(fn ($lesson) => $videoProgressStats->has($lesson->id)),
                'favorites_empty' => $favoriteQuestionIds->intersect($questionIds)->isEmpty()
                    && (int) $courseProgress->sum('incorrect_count') === 0
                    && ! $examCourseIds->contains($course->id),
            ];
        });

        // Mitteilungen der Bootsschule: an alle oder an einen gebuchten Kurs.
        $announcements = TenantAnnouncement::visible()
            ->where(fn ($q) => $q->whereNull('course_id')->orWhereIn('course_id', $courses->pluck('id')))
            ->orderByDesc('created_at')->limit(5)->get();

        // Mitteilungen des Plattformbetreibers, die auch für Lernende gedacht sind.
        $platformAnnouncements = PlatformAnnouncement::visible()->forTenant($tenant->id)
            ->where('audience', 'all')->orderByDesc('created_at')->limit(3)->get();

        // Abgelaufener Zugang: anderer Hinweis als „noch nie freigeschaltet“.
        $hadAccess = $courses->isEmpty() && Entitlement::where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereIn('status', ['active', 'expired'])->whereNotNull('valid_until')->where('valid_until', '<=', now())->exists();

        return view('dashboard', [
            'hadAccess' => $hadAccess,
            'expiringSoon' => $expiringSoon,
            'expiredCourseNames' => $courses->isEmpty() ? collect() : $expiredCourseNames,
            'firstVisit' => $progressStats->isEmpty() && $videoProgressStats->isEmpty(),
            'platformAnnouncements' => $platformAnnouncements,
            'courses' => $courseSummaries,
            'announcements' => $announcements,
        ]);
    }
}
