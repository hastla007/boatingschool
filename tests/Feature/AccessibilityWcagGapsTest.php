<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Models\ExamPaper;
use App\Models\ExamSession;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Barrierefreiheit (docs/recht/barrierefreiheit.md, bekannte Lücken):
 * Skip-Link mit Ziel inkl. Seitenüberschrift, Landmarks und h1 auf allen
 * Layouts, Tab-Muster im Admin-Bereich, verknüpfte Fehlermeldungen,
 * Textalternativen für Fortschrittsbalken/Bilder, Fokusführung bei
 * Aufdeck-Knöpfen und Dialogen, Hinweis auf das Prüfungszeitlimit.
 */
class AccessibilityWcagGapsTest extends TestCase
{
    use InteractsWithTenants;

    protected function tearDown(): void
    {
        $this->cleanUpCreatedTenantsAndUsers();
        parent::tearDown();
    }

    public function test_the_skip_link_target_includes_the_page_heading(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $response = $this->actingAsInTenant($learner, $tenant)->get('/courses')->assertOk();

        // Die h1 im Kopfbereich liegt jetzt innerhalb von <main>, der Skip-Link überspringt sie nicht mehr.
        $response->assertSeeInOrder(['href="#inhalt"', 'Zum Inhalt springen', '<nav', '<main id="inhalt" tabindex="-1"', '<h1', 'Deine Kurse', '</main>'], false);
        $this->assertSame(1, substr_count($response->getContent(), '<main'));
    }

    public function test_the_superadmin_skip_link_target_includes_the_page_heading_and_the_title_names_the_page(): void
    {
        $superadmin = $this->createSuperAdmin();

        $response = $this->actingAs($superadmin)->get('/superadmin')->assertOk();

        $response->assertSeeInOrder(['href="#inhalt"', '<main id="inhalt" tabindex="-1"', 'Superadmin-Dashboard</h1>'], false);
        $response->assertSee('<title>Superadmin-Dashboard · Superadmin', false);
    }

    public function test_the_guest_layout_puts_all_content_in_landmarks_and_names_the_page_in_the_title(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login')->assertOk();

        $response->assertSee('<title>Anmelden · '.e($tenant->name).'</title>', false);
        $response->assertSeeInOrder(['href="#inhalt"', '<header class="hidden lg:flex', '<h1', '</header>', '<header class="text-center mb-8 lg:hidden">', '<h1', '<main id="inhalt" tabindex="-1"', '</main>', '<footer>', 'Impressum', '</footer>'], false);
    }

    public function test_the_error_page_has_a_skip_link_a_main_landmark_and_an_h1(): void
    {
        $response = $this->get('/diese-seite-gibt-es-nicht-'.uniqid());

        $response->assertNotFound();
        $response->assertSeeInOrder(['<a href="#inhalt" class="skip-link">Zum Inhalt springen</a>', '<main id="inhalt" tabindex="-1" class="card">', '<h1 class="message">', '</main>'], false);
        $response->assertSee('a:focus-visible', false);
    }

    public function test_standalone_pages_mark_their_content_as_main(): void
    {
        foreach (['tenant/not-found', 'tenant/suspended', 'tenant/picker', 'maintenance', 'legal/show'] as $view) {
            $source = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringContainsString('<main id="inhalt"', $source, $view);
            $this->assertStringContainsString('</main>', $source, $view);
        }
        $this->assertStringContainsString('href="#inhalt"', file_get_contents(resource_path('views/legal/show.blade.php')));
    }

    public function test_admin_subpages_have_an_h1_and_section_headings_one_level_below(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        foreach (['/admin/statistics' => 'Statistik', '/admin/inactive' => 'Inaktive Teilnehmer', '/admin/renewals' => 'Kurszugänge verlängern', '/admin/audit' => 'Protokoll', '/admin/announcements' => 'Mitteilungen an Teilnehmer'] as $url => $heading) {
            $response = $this->actingAsInTenant($owner, $tenant)->get($url)->assertOk();
            $response->assertSee('<h1 class="font-semibold text-xl text-slate-800 dark:text-slate-200">'.$heading.'</h1>', false);
            $response->assertSee('<title>'.$heading.' · ', false);
        }

        $statistics = $this->actingAsInTenant($owner, $tenant)->get('/admin/statistics');
        $statistics->assertSee('<h2 class="font-medium text-slate-700 dark:text-slate-200 mb-3">Aktive Teilnehmer je Monat</h2>', false);
        $this->assertStringNotContainsString('<h3', $statistics->getContent());
    }

    public function test_the_statistics_bars_are_decorative_next_to_their_numbers(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)->get('/admin/statistics')->assertOk();

        $response->assertSee('<div class="flex-1 h-3 rounded-full bg-slate-100 dark:bg-slate-700" aria-hidden="true">', false);
        $response->assertSee('<span class="sr-only"> aktive Teilnehmer</span>', false);
    }

    public function test_the_admin_tabs_follow_the_aria_tab_pattern(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        // Nur der aktive Reiter wird gerendert (Reiter = Links auf ?tab=…):
        // er allein liegt in der Tab-Reihenfolge und ist mit dem einzigen
        // Bereich verknüpft; die übrigen verweisen auf keinen fehlenden Bereich.
        $tabs = ['dashboard', 'participants', 'coupons', 'branding'];
        foreach ($tabs as $tab) {
            $response = $this->actingAsInTenant($owner, $tenant)->get('/admin?tab='.$tab)->assertOk();
            $response->assertSee('href="'.route('admin.dashboard', ['tab' => $tab]).'" role="tab" id="admin-tab-'.$tab.'" aria-controls="admin-panel-'.$tab.'" aria-selected="true" tabindex="0"', false);
            $response->assertSee("role=\"tabpanel\" id=\"admin-panel-{$tab}\" aria-labelledby=\"admin-tab-{$tab}\"", false);
            foreach (array_diff($tabs, [$tab]) as $other) {
                $response->assertSee('href="'.route('admin.dashboard', ['tab' => $other]).'" role="tab" id="admin-tab-'.$other.'" aria-selected="false" tabindex="-1"', false);
                $response->assertDontSee("id=\"admin-panel-{$other}\"", false);
            }
            $this->assertSame(1, substr_count($response->getContent(), 'role="tabpanel"'));
            $response->assertSee('@keydown.arrow-right.prevent="moveTab($event, 1)"', false);
            $response->assertSee('@keydown.home.prevent="moveTab($event, \'first\')"', false);
            $response->assertSee('@keydown.space.prevent="$event.target.closest(\'[role=tab]\')?.click()"', false);
            $response->assertSee('focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500', false);
        }
    }

    public function test_admin_tables_have_named_scrollable_regions_and_no_empty_headers(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');
        $this->createTenantUser($tenant, 'learner');

        // Jeder Reiter wird einzeln gerendert (?tab=…).
        $pages = collect(array_keys(AdminDashboardController::TABS))
            ->mapWithKeys(fn ($tab) => [$tab => $this->actingAsInTenant($owner, $tenant)->get('/admin?tab='.$tab)->assertOk()->getContent()]);

        $this->assertStringContainsString('<div tabindex="0" role="region" aria-label="Tabelle: Teilnehmer (seitlich scrollbar)" class="overflow-x-auto">', $pages['participants']);
        $this->assertStringContainsString('<div tabindex="0" role="region" aria-label="Tabelle: Zugangscodes (seitlich scrollbar)" class="overflow-x-auto">', $pages['coupons']);
        foreach ($pages as $tab => $content) {
            $this->assertDoesNotMatchRegularExpression('/<th class="[^"]*"><\/th>/', $content, $tab);
        }
        $this->assertStringContainsString('<span class="sr-only">Aktionen</span>', $pages['participants']);
    }

    public function test_admin_contrast_fixes_for_delete_buttons_and_status_chips(): void
    {
        foreach (['admin/tabs/orte', 'admin/tabs/course-promo-banners'] as $view) {
            $source = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringNotContainsString('text-red-500', $source, $view);
            $this->assertStringContainsString('text-red-700 dark:text-red-400', $source, $view);
        }
        foreach (['admin/tabs/praxis-anmeldungen', 'admin/partials/_praxis-submission', 'praxis-pruefung/index'] as $view) {
            $this->assertStringNotContainsString('bg-slate-100 text-slate-500', file_get_contents(resource_path("views/{$view}.blade.php")), $view);
        }
        $this->assertStringContainsString('rounded-full bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200 text-xs mb-1', file_get_contents(resource_path('views/admin/tabs/participants.blade.php')));
    }

    public function test_the_invite_code_field_is_linked_to_its_error_message(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $response = $this->actingAsInTenant($owner, $tenant)
            ->withSession(['errors' => $this->errorBag(['code' => 'Dieser Code ist ungültig.'])])
            ->get('/admin?tab=participants')->assertOk();

        $this->assertMatchesRegularExpression('/<input type="text" id="invite_code" name="code"[^>]* aria-invalid="true" aria-describedby="invite_code-error"/', $response->getContent());
        $response->assertSee('id="invite_code-error"', false);
    }

    public function test_login_errors_are_linked_to_their_fields(): void
    {
        $tenant = $this->createTestTenant();

        $clean = $this->withSession(['dev_tenant_slug' => $tenant->slug])->get('/login')->assertOk();
        $this->assertStringNotContainsString('aria-invalid', $clean->getContent());

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug, 'errors' => $this->errorBag(['email' => 'Diese Zugangsdaten sind uns nicht bekannt.'])])
            ->get('/login')->assertOk();

        $response->assertSeeInOrder(['aria-invalid="true" aria-describedby="email-error" id="email"', '<ul class="text-sm text-red-600 dark:text-red-400 space-y-1 mt-2" id="email-error">', 'Diese Zugangsdaten sind uns nicht bekannt.'], false);
        // Das Passwortfeld ohne Fehler bleibt unmarkiert.
        $this->assertSame(1, substr_count($response->getContent(), 'aria-invalid="true"'));
    }

    public function test_register_errors_are_linked_to_their_fields(): void
    {
        $tenant = $this->createTestTenant();

        $response = $this->withSession(['dev_tenant_slug' => $tenant->slug, 'errors' => $this->errorBag(['password' => 'Das Passwort ist zu kurz.', 'coupon_code' => 'Unbekannter Code.'])])
            ->get('/register')->assertOk();

        $response->assertSee('aria-describedby="password-error"', false);
        $response->assertSee('id="password-error"', false);
        $response->assertSee('aria-describedby="coupon_code-error"', false);
        $response->assertSee('id="coupon_code-error"', false);
    }

    public function test_icons_are_hidden_from_screen_readers_unless_labelled(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');

        $content = $this->actingAsInTenant($learner, $tenant)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('aria-hidden="true" focusable="false" viewBox="0 0 24 24"', $content);
        $this->assertSame(0, preg_match('/<svg(?![^>]*aria-hidden)(?![^>]*role="img")[^>]*>/', $content), 'Jedes SVG ist dekorativ (aria-hidden) oder beschriftet (role="img")');

        $labelled = $this->blade('<x-icon name="star" aria-label="Favorit" />');
        $labelled->assertSee('role="img"', false);
        $labelled->assertDontSee('aria-hidden', false);
    }

    public function test_disclosure_buttons_expose_their_state_and_panel(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $overview = $this->actingAsInTenant($learner, $tenant)->get(route('learning.overview', $course))->assertOk();
        $this->assertMatchesRegularExpression('/:aria-expanded="open" aria-controls="lernbereich-([0-9a-f-]+)"[\s\S]*id="lernbereich-\1"/', $overview->getContent());

        $navigation = $this->actingAsInTenant($learner, $tenant)->get(route('exam.navigation.index', $course))->assertOk();
        $this->assertMatchesRegularExpression('/:aria-expanded="open" aria-controls="loesung-([0-9a-f-]+)"[\s\S]*id="loesung-\1"/', $navigation->getContent());
        // Kartenausschnitte (im Demo-Bestand ohne Bild) -- Alternativtext und Linkziel im Template.
        $chart = file_get_contents(resource_path('views/exam/navigation.blade.php'));
        $this->assertStringContainsString('alt="Seekartenausschnitt zu Navigationsaufgabe {{ $task->task_number }}"', $chart);
        $this->assertStringContainsString('(Karte in voller Größe, öffnet in neuem Tab)', $chart);

        $dropdown = file_get_contents(resource_path('views/components/dropdown.blade.php'));
        $this->assertStringContainsString("\$refs.trigger.querySelector('button, a')?.focus()", $dropdown);
    }

    public function test_progress_bars_are_decorative_next_to_their_values(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $overview = $this->actingAsInTenant($learner, $tenant)->get(route('learning.overview', $course))->assertOk();
        $overview->assertSee('<div class="flex-1 bg-slate-100 dark:bg-slate-700 rounded-full h-2" aria-hidden="true">', false);

        $ring = $this->blade('<x-progress-ring :percent="40" />');
        $ring->assertSee('aria-hidden="true" focusable="false"', false);
        $ring->assertSee('40%');
    }

    public function test_the_exam_announces_its_time_limit_and_exposes_progress_and_timer(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)->get(route('exam.intro', $course))->assertOk()
            ->assertSee('Fünf Minuten und eine Minute vor Ablauf bekommst du einen Hinweis (auch für Screenreader).')
            ->assertSee('In den Lernmodi gibt es kein Zeitlimit.');

        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        $page = $this->actingAsInTenant($learner, $tenant)->get(route('exam.show', $session))->assertOk();
        $page->assertSee('role="timer" aria-label="Verbleibende Prüfungszeit"', false);
        $page->assertSee('role="progressbar" aria-label="Bearbeitungsstand" aria-valuemin="0"', false);
        $page->assertSee('aria-valuetext="0 von', false);
    }

    public function test_question_images_without_alt_text_are_not_hidden_as_decoration(): void
    {
        foreach (['learning/question', 'exam/question', 'video/quiz'] as $view) {
            $source = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringNotContainsString('alt="{{ $media->alt_text }}"', $source, $view);
            $this->assertStringContainsString("alt=\"{{ \$media->alt_text ?: 'Abbildung zur Frage (ohne Bildbeschreibung)' }}\"", $source, $view);
        }
    }

    public function test_reveal_buttons_move_focus_to_the_revealed_answer(): void
    {
        $praxis = file_get_contents(resource_path('views/praxistrainer/show.blade.php'));
        $this->assertStringContainsString('x-on:click="revealed = true; $nextTick(() => $refs.solution.focus({ preventScroll: true }))"', $praxis);
        $this->assertStringContainsString('x-ref="solution" tabindex="-1"', $praxis);

        foreach (['learning/question', 'video/quiz'] as $view) {
            $this->assertStringContainsString('@unless ($answered) tabindex="-1" x-init="$el.focus({ preventScroll: true })" @endunless', file_get_contents(resource_path("views/{$view}.blade.php")), $view);
        }
    }

    public function test_funk_section_tabs_are_navigation_links_and_the_consent_dialog_manages_focus(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/funk/muendlich")->assertOk();

        $page->assertSee('<nav class="flex rounded-xl bg-slate-100 dark:bg-slate-800 p-1 gap-1" aria-label="Bereiche des Funk-Trainings">', false);
        $page->assertSee('aria-current="page"', false);
        $page->assertDontSee('role="tab"', false);
        $page->assertSee('aria-describedby="speech-consent-description"', false);
        $page->assertSee('data-speech-consent="decline" autofocus', false);
        $page->assertSee('data-speech-consent-error role="alert"', false);
        $page->assertSee('returnFocusTo?.focus?.();', false);
    }

    /** Fehler wie nach einer fehlgeschlagenen Validierung (über die Session, wie ShareErrorsFromSession sie liest). */
    private function errorBag(array $messages): ViewErrorBag
    {
        return (new ViewErrorBag)->put('default', new MessageBag($messages));
    }

    public function test_global_styles_show_keyboard_focus_and_respect_reduced_motion(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(':where(a, button, summary, [role="tab"], [tabindex]:not([tabindex="-1"])):focus-visible', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
    }
}
