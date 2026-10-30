<?php

namespace Tests\Feature;

use App\Models\CourseDefinition;
use App\Models\MediaAsset;
use App\Models\PraxisTask;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PraxisTrainerTest extends TestCase
{
    use InteractsWithTenants;

    private array $createdCourseIds = [];

    private array $createdMediaAssetIds = [];

    protected function tearDown(): void
    {
        // Tenant zuerst (kaskadiert praxis_progress weg), dann den
        // isolierten Test-Kurs (kaskadiert praxis_task weg).
        $this->cleanUpCreatedTenants();

        $this->onAdmin(function () {
            foreach ($this->createdCourseIds as $id) {
                CourseDefinition::withoutGlobalScopes()->where('id', $id)->delete();
            }

            // Bug-Review Testisolation: media_asset hat keinen tenant_id/FK,
            // der beim Löschen des Kurses mitkaskadiert -- ohne dieses
            // Aufräumen blieb das hier angelegte Lösungsbild dauerhaft als
            // Daten-Rest in der Tabelle stehen.
            foreach ($this->createdMediaAssetIds as $id) {
                MediaAsset::where('id', $id)->delete();
            }
        });

        // Bug-Review Testisolation: fehlte hier -- jeder per
        // createTenantUser() angelegte Nutzer blieb dauerhaft stehen, weil
        // nur der Tenant (nicht aber der Nutzer selbst) aufgeräumt wurde.
        $this->cleanUpCreatedUsers();

        parent::tearDown();
    }

    public function test_learner_without_entitlement_cannot_view_praxistrainer(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course] = $this->makeIsolatedPraxisTasks();

        $response = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer");

        $response->assertForbidden();
    }

    public function test_completing_a_task_advances_to_the_next_one_and_tracks_progress(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstTask, $secondTask] = $this->makeIsolatedPraxisTasks();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)->post(
            "/courses/{$course->id}/praxistrainer/{$firstTask->id}/complete"
        );

        $response->assertRedirect(route('praxistrainer.show', ['course' => $course, 'task' => $secondTask]));

        $this->asTenant($tenant, function () use ($tenant, $learner, $firstTask) {
            $this->assertDatabaseHas('praxis_progress', [
                'tenant_id' => $tenant->id,
                'user_id' => $learner->id,
                'praxis_task_id' => $firstTask->id,
                'completed' => true,
            ]);
        });

        $page = $this->actingAsInTenant($learner, $tenant)->get("/courses/{$course->id}/praxistrainer");
        $page->assertOk();
        $page->assertSee('1 / 2 Aufgaben', false);
    }

    /**
     * Bug-Review Oberfläche U8: der Seitenkopf (Titel + Fortschritts-
     * Statistik) stand bisher in einem "flex items-center justify-between"
     * ohne flex-wrap -- bei 360px Breite reichte der Platz dafür bei
     * längeren Kategorienamen nicht, die Seite lief horizontal über.
     */
    public function test_the_header_stacks_the_progress_stat_below_the_title_on_narrow_screens(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstTask] = $this->makeIsolatedPraxisTasks();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $firstTask]));

        $response->assertOk();
        $response->assertSee('flex flex-col sm:flex-row sm:items-center sm:justify-between', false);
    }

    /**
     * Bug-Review (Mobil-Overflow Video/Praxistrainer): der U8-Fix oben
     * betraf nur den Seitenkopf -- der umgebende Grid-Container hatte
     * unterhalb von lg: keine explizite grid-cols-1 und blieb damit
     * content-based (shrink-to-fit). Ein langer Kategorien-/Aufgabenname
     * in der Sidebar-Navigation zog die Spalte und damit die ganze Seite
     * bei 360px in die Breite, da .truncate nur greift, wenn die Breite
     * des Containers bereits feststeht.
     */
    public function test_the_layout_grid_has_an_explicit_single_column_below_the_desktop_breakpoint(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstTask] = $this->makeIsolatedPraxisTasks();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $firstTask]));

        $response->assertOk();
        $response->assertSee('grid grid-cols-1 lg:grid-cols-[280px_1fr]', false);
    }

    /**
     * Bug-Review (Zurück-Pfeile ohne Beschriftung): der "Zurück zum Kurs"-
     * Pfeil in der Sidebar besteht nur aus einem Icon ohne Text -- ohne
     * aria-label hatte er keinen für Screenreader erkennbaren Namen.
     */
    public function test_the_back_to_course_link_has_an_accessible_label(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstTask] = $this->makeIsolatedPraxisTasks();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $firstTask]));

        $response->assertOk();
        $response->assertSee('aria-label="Zurück zum Kurs"', false);
    }

    public function test_kategorie_query_param_scopes_the_praxistrainer_to_a_single_category(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, , $taskB] = $this->makeIsolatedPraxisTasksWithTwoCategories();
        $this->grantEntitlement($tenant, $learner, $course);

        $response = $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/praxistrainer?kategorie=".urlencode('Kategorie B'));

        $response->assertOk();
        $response->assertSee('Kategorie B Aufgabe');
        $response->assertDontSee('Kategorie A Aufgabe');
        $response->assertSee('0 / 1 Aufgaben', false);
    }

    /**
     * Bug-Review X9 (Abstürze durch manipulierte Werte in der Adresszeile):
     * ?kategorie[]=x statt ?kategorie=x lieferte ein Array, das
     * orderedTasks()'s typisiertem ?string-Parameter mit einem TypeError
     * unbehandelt als HTTP 500 entging -- in index(), show() und
     * complete() gleichermaßen.
     */
    public function test_array_valued_kategorie_query_param_does_not_crash(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $taskA] = $this->makeIsolatedPraxisTasksWithTwoCategories();
        $this->grantEntitlement($tenant, $learner, $course);

        $this->actingAsInTenant($learner, $tenant)
            ->get("/courses/{$course->id}/praxistrainer?kategorie[]=x")
            ->assertOk();

        $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $taskA]).'?kategorie[]=x')
            ->assertOk();

        $this->actingAsInTenant($learner, $tenant)
            ->post("/courses/{$course->id}/praxistrainer/{$taskA->id}/complete?kategorie[]=x")
            ->assertRedirect();
    }

    public function test_image_is_hidden_before_reveal_for_knots_but_shown_upfront_for_signs(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $knotTask, $signTask] = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PRAXIS-'.uniqid(),
                'name' => 'Test Praxistrainer',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $asset = MediaAsset::create([
                'asset_key' => 'test-praxis-image-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => 'http://localhost/storage/question-media/praxistrainer/test-solution-image.png',
            ]);
            $this->createdMediaAssetIds[] = $asset->id;

            $knotTask = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-KNOT',
                'kategorie' => 'Seemannsknoten',
                'frage' => 'Führe den Knoten „Testknoten“ vor.',
                'antwort' => 'Musterlösung Testknoten',
                'media_asset_id' => $asset->id,
                'sort_order' => 1,
            ]);
            $signTask = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-SIGN',
                'kategorie' => 'Zeichen auf See',
                'frage' => 'Was bedeutet das dargestellte Zeichen?',
                'antwort' => 'Musterlösung Zeichen',
                'media_asset_id' => $asset->id,
                'sort_order' => 2,
            ]);

            return [$course, $knotTask, $signTask];
        });
        $this->grantEntitlement($tenant, $learner, $course);

        $knotPage = $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $knotTask]))
            ->getContent();
        $this->assertGreaterThan(
            strpos($knotPage, 'MUSTERLÖSUNG'),
            strpos($knotPage, 'test-solution-image.png'),
            'Für Knoten muss das Bild erst nach der Musterlösung im HTML stehen.'
        );

        $signPage = $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $signTask]))
            ->getContent();
        $this->assertLessThan(
            strpos($signPage, 'Musterlösung anzeigen'),
            strpos($signPage, 'test-solution-image.png'),
            'Für Zeichen muss das Bild schon vor dem Musterlösung-Button im HTML stehen.'
        );
    }

    /**
     * Bug-Review (Hoch): unterkategorie ist in Kategorien wie "Zeichen auf
     * See" ("Was bedeutet das dargestellte Zeichen?") die Lösung selbst
     * (z. B. "Überholverbot") -- sie stand bisher unbedingt im Seitentitel,
     * in der Aufgabenliste (auch für ANDERE Aufgaben als die aktuell
     * angezeigte) und im Bild-Alt-Text, jeweils bevor "Musterlösung
     * anzeigen" geklickt wurde.
     */
    public function test_a_signs_category_unterkategorie_does_not_leak_the_solution_before_reveal(): void
    {
        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        [$course, $firstTask] = $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PRAXIS-'.uniqid(),
                'name' => 'Test Praxistrainer',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $asset = MediaAsset::create([
                'asset_key' => 'test-praxis-image-'.uniqid(),
                'media_type' => 'image',
                'storage_path' => 'http://localhost/storage/question-media/praxistrainer/test-sign.png',
            ]);
            $this->createdMediaAssetIds[] = $asset->id;

            $firstTask = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-SIGN-1',
                'kategorie' => 'Zeichen auf See',
                'unterkategorie' => 'Geheimlösung-Ueberholverbot',
                'frage' => 'Was bedeutet das dargestellte Schifffahrtszeichen?',
                'antwort' => 'Geheimlösung-Ueberholverbot: zwei Pfeile mit rotem Schrägstrich.',
                'media_asset_id' => $asset->id,
                'sort_order' => 1,
            ]);
            PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-SIGN-2',
                'kategorie' => 'Zeichen auf See',
                'unterkategorie' => 'Geheimlösung-Geschwindigkeit',
                'frage' => 'Was bedeutet das dargestellte Schifffahrtszeichen?',
                'antwort' => 'Geheimlösung-Geschwindigkeit: Höchstgeschwindigkeit einhalten.',
                'sort_order' => 2,
            ]);

            return [$course, $firstTask];
        });
        $this->grantEntitlement($tenant, $learner, $course);

        $html = $this->actingAsInTenant($learner, $tenant)
            ->get(route('praxistrainer.show', ['course' => $course, 'task' => $firstTask]))
            ->assertOk()
            ->getContent();

        // Die andere Aufgabe wird auf dieser Seite nirgends "aufgelöst" --
        // ihre unterkategorie darf deshalb überhaupt nicht im HTML stehen.
        $this->assertStringNotContainsString('Geheimlösung-Geschwindigkeit', $html);

        // Die aktuelle Aufgabe enthält ihre unterkategorie zwangsläufig
        // INNERHALB der Musterlösung selbst (antwort beginnt im echten
        // Katalog genauso, z. B. "Überholverbot: zwei Pfeile ..."). Alpine
        // blendet den Musterlösung-Block nur per CSS aus, er steht trotzdem
        // im HTML -- entscheidend ist deshalb nicht, OB die unterkategorie
        // im HTML auftaucht, sondern DASS ihr erstes Auftreten erst NACH dem
        // "Musterlösung anzeigen"-Button kommt (Titel, Aufgabenliste und der
        // erste Bild-Alt-Text stehen davor im DOM).
        $buttonPosition = strpos($html, 'Musterlösung anzeigen');
        $this->assertNotFalse($buttonPosition, 'Button "Musterlösung anzeigen" nicht gefunden.');

        $firstLeakPosition = strpos($html, 'Geheimlösung-Ueberholverbot');
        $this->assertNotFalse($firstLeakPosition, 'Testvoraussetzung: die unterkategorie muss zumindest innerhalb der Musterlösung auftauchen.');
        $this->assertGreaterThan(
            $buttonPosition,
            $firstLeakPosition,
            'unterkategorie darf nur innerhalb des Musterlösung-Blocks stehen (Titel/Liste/Alt-Text davor sind ein Leak).'
        );
    }

    /** @return array{0: CourseDefinition, 1: PraxisTask, 2: PraxisTask} */
    private function makeIsolatedPraxisTasks(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PRAXIS-'.uniqid(),
                'name' => 'Test Praxistrainer',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $first = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-0001',
                'kategorie' => 'Testkategorie',
                'frage' => 'Testaufgabe 1',
                'antwort' => 'Musterlösung 1',
                'sort_order' => 1,
            ]);
            $second = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-0002',
                'kategorie' => 'Testkategorie',
                'frage' => 'Testaufgabe 2',
                'antwort' => 'Musterlösung 2',
                'sort_order' => 2,
            ]);

            return [$course, $first, $second];
        });
    }

    /** @return array{0: CourseDefinition, 1: PraxisTask, 2: PraxisTask} */
    private function makeIsolatedPraxisTasksWithTwoCategories(): array
    {
        return $this->onAdmin(function () {
            $course = CourseDefinition::withoutGlobalScopes()->create([
                'tenant_id' => null,
                'code' => 'TEST-PRAXIS-'.uniqid(),
                'name' => 'Test Praxistrainer',
                'course_type' => 'full',
                'status' => 'published',
            ]);
            $this->createdCourseIds[] = $course->id;

            $taskA = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-A1',
                'kategorie' => 'Kategorie A',
                'frage' => 'Kategorie A Aufgabe',
                'antwort' => 'Musterlösung A',
                'sort_order' => 1,
            ]);
            $taskB = PraxisTask::create([
                'course_id' => $course->id,
                'content_id' => 'TEST-PRAXIS-B1',
                'kategorie' => 'Kategorie B',
                'frage' => 'Kategorie B Aufgabe',
                'antwort' => 'Musterlösung B',
                'sort_order' => 2,
            ]);

            return [$course, $taskA, $taskB];
        });
    }
}
