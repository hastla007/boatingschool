<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review (Y8: Anrede inkonsistent -- "Du/Dein" mal groß, mal klein):
 * mehrere Lernenden-Seiten schrieben "Du"/"Dein"/"Dich"/"Dir" mitten im Satz
 * groß (ältere Stilform), während der Rest der Anwendung durchgehend die
 * moderne Kleinschreibung mitten im Satz verwendet (z. B.
 * "Schön, dass du wieder da bist." auf dem Dashboard). Betroffen waren vor
 * allem exam/papers.blade.php ("...sodass Du Dich...", "findest Du alle...")
 * und exam/navigation.blade.php, außerdem einzelne Stellen in
 * courses/show.blade.php, courses/index.blade.php, exam/question.blade.php,
 * video/quiz.blade.php und learning/question.blade.php. Satzanfänge
 * ("Du hast...", Überschriften wie "Dein Fortschritt") bleiben unverändert
 * groß -- das ist unabhängig vom Du/Sie-Stil immer korrekt.
 *
 * Die im selben Bug-Report genannte "Funk-Praxis siezt": es existiert
 * weder ein eigenständiges Funk-/Funktrainer-Feature noch irgendein
 * Praxistrainer-Inhalt für SRC/UBI (0 PraxisTask-Datensätze, siehe Y2-
 * Investigation), noch "Sie"-Text im SRC/UBI-spezifischen
 * Anmeldeformular "Anmeldung Funkzeugnis-Prüfung" (PraxisFormTemplateSeeder)
 * oder der allgemeinen Prüfung-buchen-Seite -- nichts davon wurde
 * gefunden, das korrigiert werden könnte.
 */
class InformalAddressCapitalizationTest extends TestCase
{
    use InteractsWithTenants;

    public function test_exam_papers_overview_uses_lowercase_du_mid_sentence(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('exam.intro', $course));

        $response->assertOk();
        $response->assertSee('Hier findest du alle', false);
        $response->assertSee('sodass du dich gezielt und effizient', false);
        $response->assertDontSee('findest Du alle', false);
        $response->assertDontSee('sodass Du Dich', false);
    }

    public function test_exam_navigation_overview_uses_lowercase_du_mid_sentence(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('exam.navigation.index', $course));

        $response->assertOk();
        $response->assertSee('Hier findest du alle', false);
        $response->assertDontSee('findest Du alle', false);
    }

    public function test_course_page_uses_lowercase_du_mid_sentence(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $learner, $course);

        $this->onAdmin(function () use ($tenant) {
            $tenant->branding->update(['phone_support_enabled' => false, 'email_support_enabled' => false, 'street' => 'Hafenstraße 1']);
        });

        $response = $this->actingAsInTenant($learner, $tenant)->get(route('courses.show', $course));

        $response->assertOk();
        $response->assertSee('Kontaktiere deine Bootsschule', false);
        $response->assertSee('Wir sind gerne für dich da', false);
        $response->assertDontSee('Kontaktiere Deine Bootsschule', false);
        $response->assertDontSee('für Dich da', false);
    }
}
