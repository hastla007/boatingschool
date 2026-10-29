<?php

namespace App\Http\Controllers;

use App\Models\CourseDefinition;
use App\Models\PraxisFormSubmission;
use App\Models\PraxisFormTemplate;
use App\Models\TenantAddon;
use App\Models\TenantCourseLocation;
use App\Services\CourseProgressService;
use App\Services\EntitlementService;
use App\Support\ExamRegistration;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PraxisPruefungController extends Controller
{
    public function index(
        Request $request,
        TenantContext $tenantContext,
        EntitlementService $entitlements,
        CourseProgressService $courseProgress,
        CourseDefinition $course,
    ): View|RedirectResponse {
        $tenant = $tenantContext->tenant();
        $user = $request->user();

        abort_unless($entitlements->hasAccess($tenant, $user, $course), 403, 'Für diesen Kurs liegt kein aktives Entitlement vor.');

        // Teilnehmer meldet sich selbst an (ExamRegistration::SELF): statt des Termintools die Seite "Prüfung buchen".
        if (ExamRegistration::isSelf($tenant, $course)) {
            return redirect()->route('exam-booking', $course);
        }

        // Bug-Review (Gates vergleichen den gerundeten Wert):
        // overallPercent() rundet für die Anzeige -- 49,5% gefestigte
        // Fragen zeigten sich bisher als "50%" und erfüllten damit
        // fälschlich eine Schwelle von 50%. Die Gates unten vergleichen
        // deshalb den ungerundeten $exactPercent, nur die Anzeige im
        // "locked"-Zustand nutzt weiterhin den gerundeten $percent.
        $exactPercent = $courseProgress->exactPercent($course, $tenant, $user);
        $percent = (int) round($exactPercent);

        $template = PraxisFormTemplate::where('active', true)
            ->whereJsonContains('course_ids', $course->id)
            ->first();

        if (! $template) {
            // Noch kein Praxisformular zugewiesen: altes Verhalten
            // beibehalten (Platzhalterseite, gated über die
            // bootsschulweite Branding-Schwelle statt eines Formulars).
            $threshold = $tenant->branding->exam_readiness_threshold_percent;
            abort_unless($exactPercent >= $threshold, 403, "Für die Buchung von Prüfung & Praxis werden mindestens {$threshold}% Kursfortschritt benötigt.");

            return view('praxis-pruefung.index', ['course' => $course, 'state' => 'unavailable']);
        }

        if ($exactPercent < $template->min_progress_percent) {
            return view('praxis-pruefung.index', [
                'course' => $course,
                'state' => 'locked',
                'percent' => $percent,
                'threshold' => $template->min_progress_percent,
            ]);
        }

        $submission = PraxisFormSubmission::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->with('examAppointments.location')
            ->first();

        if ($submission) {
            return view('praxis-pruefung.index', [
                'course' => $course,
                'state' => 'submitted',
                'template' => $submission->template ?? $template,
                'submission' => $submission,
            ]);
        }

        // Adresse und Geburtsdatum werden einmalig im Profil erfasst (nicht
        // im Praxisformular selbst), da sie kurs-/anmeldungsübergreifend
        // gelten. Admins können beim Anlegen eines Teilnehmers zwar die
        // Adresse hinterlegen (siehe ParticipantController::invite), das
        // Geburtsdatum aber bewusst nicht -- es muss immer vom Lerner selbst
        // kommen, daher greift dieses Gate auch dann noch.
        if (! $user->hasCompleteRegistrationProfile($tenant)) {
            return view('praxis-pruefung.index', ['course' => $course, 'state' => 'incomplete-profile']);
        }

        $locations = TenantCourseLocation::where('tenant_id', $tenant->id)
            ->whereJsonContains('course_ids', $course->id)
            ->orderBy('sort_order')
            ->get();

        // Gleiche Auswahl wie der Addon-Banner auf der Kursseite (siehe
        // CourseController::show) -- es gibt keine Bestell-/Kauf-Erfassung
        // im System (der Kauf läuft extern über offer_url), daher ist dies
        // ein Hinweis auf ein zum Kurs passendes, aktives Addon-Angebot,
        // kein Nachweis, dass der Lerner es noch nicht erworben hat.
        $recommendedAddon = TenantAddon::where('tenant_id', $tenant->id)
            ->where('trigger_course_id', $course->id)
            ->where('active', true)
            ->whereHas('addonTemplate', fn ($q) => $q->where('active', true))
            ->with('addonTemplate')
            ->get()
            ->filter(fn ($tenantAddon) => $exactPercent >= $tenantAddon->trigger_percent)
            ->sortByDesc('trigger_percent')
            ->first();

        return view('praxis-pruefung.index', [
            'course' => $course,
            'state' => 'form',
            'template' => $template,
            'locations' => $locations,
            'recommendedAddon' => $recommendedAddon,
        ]);
    }
}
