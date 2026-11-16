<?php

namespace Tests\Feature\Api;

use App\Models\CourseExamRegistration;
use App\Models\NavigationProgress;
use App\Models\NavigationTask;
use App\Models\PraxisProgress;
use App\Models\PraxisTask;
use App\Models\RadioTrainingProgress;
use App\Models\VideoLesson;
use App\Support\ExamRegistration;
use App\Support\FunkTraining;
use Illuminate\Support\Str;

/** Mobile-API: Praxistrainer, Navigation (Selbstbewertung), Video-Metadaten, Funk-Training und Prüfungsanmeldung. */
class TrainingApiTest extends MobileApiTestCase
{
    public function test_praxis_cards_have_three_states_shared_with_the_web_trainer(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        $list = $this->api('GET', "/courses/{$course->id}/praxis", $token, $tenant)->assertOk();
        $this->assertGreaterThan(0, $list->json('meta.total'));
        $this->assertSame(0, $list->json('meta.known'));
        $card = $list->json('data.0.cards.0');
        $this->assertSame('open', $card['status']);
        $this->assertArrayHasKey('answer', $card);

        // practice: nicht gemeistert; known: im Web „Gemeistert“.
        $this->api('PUT', "/courses/{$course->id}/praxis/{$card['id']}", $token, $tenant, ['status' => 'practice'])
            ->assertOk()->assertJsonPath('data.status', 'practice');
        $this->assertFalse($this->praxisRow($tenant->id, $user->id, $card['id'])->completed);
        $this->api('PUT', "/courses/{$course->id}/praxis/{$card['id']}", $token, $tenant, ['status' => 'known'])->assertOk();
        $this->assertTrue($this->praxisRow($tenant->id, $user->id, $card['id'])->completed);
        $this->assertSame(1, $this->api('GET', "/courses/{$course->id}/praxis", $token, $tenant)->json('meta.known'));

        // Web „Wieder öffnen“ setzt auch den App-Stand zurück, „Gemeistert“ wieder auf known.
        $this->actingAsInTenant($user, $tenant)->post(route('praxistrainer.reopen', ['course' => $course->id, 'task' => $card['id']]))->assertRedirect();
        $this->assertSame('open', $this->praxisRow($tenant->id, $user->id, $card['id'])->status);
        $this->actingAsInTenant($user, $tenant)->post(route('praxistrainer.complete', ['course' => $course->id, 'task' => $card['id']]))->assertRedirect();
        $this->assertSame('known', $this->praxisRow($tenant->id, $user->id, $card['id'])->status);

        $this->api('PUT', "/courses/{$course->id}/praxis/{$card['id']}", $token, $tenant, ['status' => 'mastered'])->assertStatus(422);
        // Karte eines anderen Kurses.
        $foreign = $this->onAdmin(fn () => PraxisTask::where('course_id', '!=', $course->id)->where('active', true)->value('id'));
        $this->api('PUT', "/courses/{$course->id}/praxis/{$foreign}", $token, $tenant, ['status' => 'known'])->assertNotFound();
    }

    public function test_praxis_progress_is_per_school_and_requires_course_access(): void
    {
        $tenant = $this->createTestTenant();
        $other = $this->createTestTenant('Andere Schule');
        $user = $this->createTenantUser($tenant, 'learner');
        $stranger = $this->createTenantUser($other, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $this->grantEntitlement($other, $stranger, $course);
        $token = $this->apiLogin($user);
        $cardId = $this->api('GET', "/courses/{$course->id}/praxis", $token, $tenant)->json('data.0.cards.0.id');
        $this->api('PUT', "/courses/{$course->id}/praxis/{$cardId}", $token, $tenant, ['status' => 'known'])->assertOk();

        $this->assertSame(0, $this->api('GET', "/courses/{$course->id}/praxis", $stranger, $other)->json('meta.known'));
        // Ohne Mitgliedschaft in der anderen Schule kein Zugriff.
        $this->api('GET', "/courses/{$course->id}/praxis", $token, $other)->assertStatus(403);
        // Ohne Kurszugang.
        $noAccess = $this->createTenantUser($tenant, 'learner');
        $this->api('GET', "/courses/{$course->id}/praxis", $noAccess, $tenant)->assertStatus(403)->assertJsonPath('code', 'ENTITLEMENT_REQUIRED');
    }

    public function test_navigation_self_assessment_has_three_states_and_a_new_answer_resets_it(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $taskId = $this->api('GET', "/courses/{$course->id}/navigation-tasks", $token, $tenant)->json('data.0.id');
        $part = $this->api('GET', "/navigation-tasks/{$taskId}?show_solution=true", $token, $tenant)->json('data.parts.0');

        $this->api('POST', "/navigation-tasks/{$taskId}/parts/{$part['id']}", $token, $tenant, ['self_assessment' => 'partly'])
            ->assertOk()->assertJsonPath('data.self_assessment', 'partly')->assertJsonPath('data.done', true)
            ->assertJsonPath('data.solution', $part['solution']);
        $row = $this->navRow($tenant->id, $user->id, $part['id']);
        $this->assertSame('partly', $row->self_assessment);
        $this->assertFalse($row->correct);
        $this->assertSame('partly', $this->api('GET', "/navigation-tasks/{$taskId}", $token, $tenant)->json('data.parts.0.self_assessment'));

        // Neue Antwort ohne Bewertung setzt die Bewertung zurück.
        $this->api('POST', "/navigation-tasks/{$taskId}/parts/{$part['id']}", $token, $tenant, ['answer' => 'meine Lösung'])
            ->assertOk()->assertJsonPath('data.my_answer', 'meine Lösung')->assertJsonPath('data.self_assessment', null);
        $this->api('POST', "/navigation-tasks/{$taskId}/parts/{$part['id']}", $token, $tenant, ['self_assessment' => 'correct'])
            ->assertOk()->assertJsonPath('data.my_answer', 'meine Lösung');
        $this->assertTrue($this->navRow($tenant->id, $user->id, $part['id'])->correct);
        // Weder Antwort noch Bewertung: abgelehnt, der Stand bleibt.
        $this->api('POST', "/navigation-tasks/{$taskId}/parts/{$part['id']}", $token, $tenant, ['answer' => ''])->assertStatus(422);
        $this->assertSame('meine Lösung', $this->navRow($tenant->id, $user->id, $part['id'])->answer);

        // Bisheriger PUT-Weg speichert die Selbstbewertung mit.
        $this->api('PUT', "/navigation-tasks/{$taskId}/parts/{$part['id']}/answer", $token, $tenant, ['self_assessment' => 'incorrect'])
            ->assertOk()->assertJsonPath('data.self_assessment', 'wrong')->assertJsonPath('data.correct', false);
        $this->api('POST', "/navigation-tasks/{$taskId}/parts/{$part['id']}", $token, $tenant, ['self_assessment' => 'incorrect'])->assertStatus(422);

        $foreign = $this->onAdmin(fn () => NavigationTask::where('course_id', $this->existingCourse('ADD-BIN-TO-SEE')->id)->with('questions')->first());
        $this->api('POST', "/navigation-tasks/{$foreign->id}/parts/{$foreign->questions->first()->id}", $token, $tenant, ['self_assessment' => 'correct'])->assertStatus(403);
    }

    public function test_video_list_reports_progress_and_next_lesson(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        // Die mitgelieferten Videos sind Platzhalter (nicht abschließbar, zählen
        // nicht mit): für diesen Test zwei Lektionen mit echtem Video.
        $lessons = $this->onAdmin(fn () => VideoLesson::whereHas('module', fn ($q) => $q->where('course_id', $course->id))
            ->orderBy('video_module_id')->orderBy('sort_order')->limit(2)->get());
        $this->onAdmin(fn () => VideoLesson::whereIn('id', $lessons->pluck('id'))->update(['video_url' => 'https://videos.example.test/lektion.mp4']));
        $this->beforeApplicationDestroyed(fn () => $this->onAdmin(fn () => VideoLesson::whereIn('id', $lessons->pluck('id'))->update(['video_url' => VideoLesson::PLACEHOLDER_URL])));

        $list = $this->api('GET', "/courses/{$course->id}/videos", $token, $tenant)->assertOk();
        $first = $list->json('meta.next_lesson_id');
        $this->assertSame($course->id, $list->json('meta.course_id'));
        $this->assertSame(0, $list->json('meta.completed'));
        $this->assertSame($first, $list->json('meta.next_lesson_id'));
        $this->assertArrayHasKey('group', $list->json('data.0'));

        $this->api('PUT', "/videos/{$first}/progress", $token, $tenant, ['position_seconds' => 1, 'completed' => true])->assertOk();
        $after = $this->api('GET', "/courses/{$course->id}/videos", $token, $tenant);
        $this->assertSame(1, $after->json('meta.completed'));
        $this->assertNotSame($first, $after->json('meta.next_lesson_id'));
    }

    public function test_radio_trainer_shares_progress_with_the_web_and_checks_access(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SRC-UBI');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);
        $base = "/courses/{$course->id}/radio";

        $index = $this->api('GET', $base, $token, $tenant)->assertOk();
        $this->assertSame(['SRC', 'UBI'], $index->json('data.catalogs'));
        $this->assertEqualsCanonicalizing(['translation', 'dictation', 'spelling'], collect($index->json('data.texts'))->pluck('type')->unique()->all());
        $this->api('GET', "{$base}/spelling", $token, $tenant)->assertOk()->assertJsonCount(26, 'data.letters')->assertJsonCount(10, 'data.digits');

        // Übersetzung: Lösung erst nach der Auswertung in dieser Richtung.
        $text = FunkTraining::texts('SRC')[1];
        $this->api('GET', "{$base}/texts/{$text['key']}", $token, $tenant)->assertOk()
            ->assertJsonPath('data.solution_hidden', true)->assertJsonPath('data.text_en', null)->assertJsonPath('data.next_id', FunkTraining::texts('SRC')[2]['key']);
        $this->api('POST', "{$base}/texts/{$text['key']}/evaluate", $token, $tenant, ['direction' => 'de-en'])->assertStatus(422);
        $evaluated = $this->api('POST', "{$base}/texts/{$text['key']}/evaluate", $token, $tenant, ['direction' => 'de-en', 'transcript' => $text['de']])->assertOk();
        $this->assertSame(100, $evaluated->json('data.transcript.percent'));
        $this->assertNull($evaluated->json('data.translation'));
        $this->api('GET', "{$base}/texts/{$text['key']}", $token, $tenant)->assertJsonPath('data.text_en', $text['en']);
        $this->api('PUT', "{$base}/texts/{$text['key']}/done", $token, $tenant, ['done' => true])->assertOk();

        // UBI-Aufnahme: gespeichert wie im Web ({transcript}); Abgabe ist nicht auswertbar.
        $dictation = FunkTraining::ubiTexts('aufnahme')[1];
        $this->api('POST', "{$base}/texts/{$dictation['key']}/evaluate", $token, $tenant, ['transcript' => 'Hallo'])->assertOk();
        $this->assertSame(['transcript' => 'Hallo'], $this->radioRow($tenant->id, $user->id, 'text', $dictation['key'])->answers);
        $this->api('POST', "{$base}/texts/".FunkTraining::ubiTexts('abgabe')[1]['key'].'/evaluate', $token, $tenant, ['transcript' => 'x'])->assertStatus(422);
        $this->api('GET', "{$base}/texts/TXT-SRC-99", $token, $tenant)->assertNotFound();

        // Vokabeln: drei Mal gewusst = gelernt; doppelt gesendet (alter Stand) zählt nicht.
        $card = $this->api('GET', "{$base}/vocabulary?catalog=SRC", $token, $tenant)->assertOk()->json('data.0');
        foreach ([0, 1, 2] as $streak) {
            $this->api('POST', "{$base}/vocabulary/{$card['id']}/answers", $token, $tenant, ['known' => true, 'streak' => $streak])
                ->assertOk()->assertJsonPath('data.streak', $streak + 1)->assertJsonPath('data.repeated', false);
        }
        $this->api('POST', "{$base}/vocabulary/{$card['id']}/answers", $token, $tenant, ['known' => true, 'streak' => 2])
            ->assertOk()->assertJsonPath('data.repeated', true)->assertJsonPath('data.streak', 3)->assertJsonPath('data.learned', true)->assertJsonPath('data.learned_count', 1);
        $this->api('GET', "{$base}/vocabulary", $token, $tenant)->assertStatus(422);
        $this->api('POST', "{$base}/vocabulary/VOC-SRC-unbekannt/answers", $token, $tenant, ['known' => true])->assertNotFound();

        // Praxisaufgabe: Auswertung mit Prüfpunkten, ohne Lösungen vorab.
        $scenario = $this->api('GET', "{$base}/practice/SRC-01", $token, $tenant)->assertOk()->json('data');
        $this->assertArrayNotHasKey('solution', $scenario['fields'][0]);
        $field = $scenario['fields'][0]['name'];
        $this->api('POST', "{$base}/practice/SRC-01/evaluate", $token, $tenant, ['answers' => [$field => '']])->assertStatus(422);
        $result = $this->api('POST', "{$base}/practice/SRC-01/evaluate", $token, $tenant, ['answers' => [$field => 'MAYDAY']])->assertOk();
        $this->assertGreaterThan(0, $result->json('data.total'));
        $this->assertNotNull($result->json("data.fields.{$field}.solution"));
        $this->api('PUT', "{$base}/practice/SRC-01/done", $token, $tenant, ['done' => true])->assertOk();
        $this->assertTrue(collect($this->api('GET', "{$base}/practice", $token, $tenant)->json('data.0.tasks'))->firstWhere('id', 'SRC-01')['done']);
        $this->api('GET', "{$base}/samples", $token, $tenant)->assertOk()->assertJsonPath('data.0.catalog', 'SRC');

        // Der Web-Trainer sieht denselben Stand.
        $this->actingAsInTenant($user, $tenant)->get(route('funk.praxis.index', $course))->assertOk()->assertViewHas('completed', ['SRC-01']);

        // Kein Funkkurs: 404; ohne Kurszugang: 403; fremde Schule sieht nichts.
        $sbf = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $sbf);
        $this->api('GET', "/courses/{$sbf->id}/radio", $token, $tenant)->assertNotFound();
        $noAccess = $this->createTenantUser($tenant, 'learner');
        $this->api('GET', $base, $noAccess, $tenant)->assertStatus(403);
        $other = $this->createTestTenant('Andere Schule');
        $stranger = $this->createTenantUser($other, 'learner');
        $this->grantEntitlement($other, $stranger, $course);
        $this->assertFalse(collect($this->api('GET', "{$base}/practice", $stranger, $other)->json('data.0.tasks'))->firstWhere('id', 'SRC-01')['done']);
    }

    public function test_exam_registration_follows_the_schools_mode(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $token = $this->apiLogin($user);

        // Standard: die Schule meldet an (Termintool), keine Anleitung.
        $this->api('GET', "/courses/{$course->id}/exam-registration", $token, $tenant)->assertOk()
            ->assertJsonPath('data.mode', ExamRegistration::SCHOOL)->assertJsonPath('data.uses_appointment_tool', true)
            ->assertJsonPath('data.guides', [])->assertJsonPath('data.web_url', null)->assertJsonPath('data.registration_in_app', false);

        $this->onAdmin(fn () => CourseExamRegistration::create(['tenant_id' => $tenant->id, 'course_id' => $course->id, 'mode' => ExamRegistration::SELF]));
        $self = $this->api('GET', "/courses/{$course->id}/exam-registration", $token, $tenant)->assertOk()
            ->assertJsonPath('data.mode', ExamRegistration::SELF)->assertJsonPath('data.uses_appointment_tool', false);
        $this->assertNotEmpty($self->json('data.guides'));
        $this->assertNotEmpty($self->json('data.guides.0.variants'));
        $this->assertStringEndsWith("/courses/{$course->id}/pruefung-buchen", $self->json('data.web_url'));

        $noAccess = $this->createTenantUser($tenant, 'learner');
        $this->api('GET', "/courses/{$course->id}/exam-registration", $noAccess, $tenant)->assertStatus(403);
        $this->api('GET', '/courses/'.Str::uuid().'/exam-registration', $token, $tenant)->assertNotFound();
    }

    private function praxisRow(string $tenantId, string $userId, string $taskId): PraxisProgress
    {
        return $this->onAdmin(fn () => PraxisProgress::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'praxis_task_id' => $taskId])->firstOrFail());
    }

    private function navRow(string $tenantId, string $userId, string $questionId): NavigationProgress
    {
        return $this->onAdmin(fn () => NavigationProgress::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'navigation_question_id' => $questionId])->firstOrFail());
    }

    private function radioRow(string $tenantId, string $userId, string $type, string $key): RadioTrainingProgress
    {
        return $this->onAdmin(fn () => RadioTrainingProgress::where(['tenant_id' => $tenantId, 'user_id' => $userId, 'item_type' => $type, 'item_key' => $key])->firstOrFail());
    }
}
