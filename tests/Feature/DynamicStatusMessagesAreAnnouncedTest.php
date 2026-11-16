<?php

namespace Tests\Feature;

use App\Models\ExamPaper;
use App\Models\ExamSession;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Barrierefreiheit (WCAG 4.1.3 Statusmeldungen): Meldungen, die JavaScript
 * ohne Seitenwechsel einblendet oder ändert, stehen in einer Live-Region
 * (role="status"/aria-live), die schon beim Laden im DOM ist -- sonst sagen
 * Screenreader sie nicht an.
 */
class DynamicStatusMessagesAreAnnouncedTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_seat_warning_and_manager_search_on_the_learning_group_page_are_live_regions(): void
    {
        $tenant = $this->createTestTenant();
        $owner = $this->createTenantUser($tenant, 'owner');

        $page = $this->actingAsInTenant($owner, $tenant)->get(route('admin.learning-groups.create'))->assertOk();
        // Warnung „Mehr Plätze als freie Kurscodes“ (Alpine x-show) in einer dauerhaft vorhandenen Live-Region.
        $page->assertSee('role="status" aria-live="polite"><span style="display: none" x-show="selected && seats >', false);
        // Trefferzahl und Auswahl der Verwaltersuche werden angesagt.
        $page->assertSee('role="status" aria-live="polite" x-text="message" data-manager-search-status', false);
        // Ohne JavaScript kein Combobox-Rollenspiel: die Rollen setzt erst Alpine.
        $page->assertDontSee('role="combobox"', false)->assertSee('x-bind:role="\'combobox\'"', false)
            ->assertSee('x-bind:aria-activedescendant', false)->assertSee('role="listbox"', false);
    }

    public function test_the_exam_favorite_toggle_reports_its_result(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);
        $paper = $this->onAdmin(fn () => ExamPaper::where('course_id', $course->id)->orderBy('paper_number')->firstOrFail());
        $this->actingAsInTenant($learner, $tenant)->post("/courses/{$course->id}/exam/papers/{$paper->id}")->assertRedirect();
        $session = $this->asTenant($tenant, fn () => ExamSession::where('tenant_id', $tenant->id)->where('user_id', $learner->id)->firstOrFail());

        $this->actingAsInTenant($learner, $tenant)->get(route('exam.show', $session))->assertOk()
            ->assertSee('<p id="favorite-status" class="sr-only" role="status" aria-live="polite"></p>', false)
            ->assertSee("status.textContent = nowFavorite ? 'Als Favorit gespeichert.' : 'Aus den Favoriten entfernt.';", false);
    }

    public function test_other_script_driven_messages_use_live_regions(): void
    {
        $view = fn (string $name) => file_get_contents(resource_path("views/{$name}.blade.php"));

        // Lernmodus: dieselbe Rückmeldung zum Favoriten-Knopf.
        $this->assertStringContainsString('<p id="favorite-status" class="sr-only" role="status" aria-live="polite"></p>', $view('learning/question'));
        // Kartenaufgabe in der Prüfung: Restzeit-Warnungen wie bei den Bogenfragen.
        $this->assertStringContainsString('<span id="exam-timer-announce" class="sr-only" aria-live="assertive"></span>', $view('exam/navigation-part'));
        $this->assertStringContainsString("announce.textContent = 'Noch 1 Minute Prüfungszeit.'", $view('exam/navigation-part'));
        // Prüfung, offene Frage: der Fokus springt auf die nachgeladene Musterantwort.
        $this->assertStringContainsString('tabindex="-1" x-init="$nextTick(() => $el.focus({ preventScroll: true }))"', $view('exam/question'));
        // Funk-Vokabeln: Zähler und Kartenrückseite.
        $vocab = $view('funk/_vocab');
        $this->assertStringContainsString('role="status" aria-live="polite" aria-atomic="true"><span x-text="learned"></span>', $vocab);
        $this->assertStringContainsString('<p class="sr-only" role="status" aria-live="polite" x-text="flipped ?', $vocab);
        // „Gespeichert.“ nach dem Passwortwechsel.
        $this->assertStringContainsString('role="status"', $view('profile/partials/update-password-form'));
    }
}
