<?php

namespace Tests\Feature;

use App\Models\ContentQuestionRevision;
use Tests\Feature\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Bug-Review B1: "Nächste Frage" im Themen-Modus baute die Query-String
 * manuell per Konkatenation ("&topic=".$topic) zusammen statt sie über
 * route() URL-kodieren zu lassen. Ein Thema mit "&" (z. B. "Lichter,
 * Zeichen & Schallsignale", ein echtes Thema im Fragenkatalog) wurde dabei
 * an der Stelle abgeschnitten -- alles danach landete als eigener,
 * unbekannter Query-Parameter, und der nächste Request fand keine Fragen
 * mehr zu diesem (jetzt unvollständigen) Thema.
 */
class TopicQueryStringEncodingTest extends TestCase
{
    use InteractsWithTenants;

    public function test_the_next_question_link_keeps_an_ampersand_in_the_topic_name_intact(): void
    {
        // Bug-Review N23: ohne orderBy() lieferte Postgres je nach
        // physischer Zeilenreihenfolge (die sich z. B. durch UPDATEs
        // anderer Tests auf den Fragenkatalog verschiebt) mal dieses, mal
        // ein anderes der zahlreichen "&"-Themen im Katalog zurück -- im
        // Komplettlauf der Suite damit nicht deterministisch denselben
        // Datensatz wie im isolierten Lauf dieses Tests.
        $revision = $this->onAdmin(fn () => ContentQuestionRevision::where('topic', 'like', '%&%')
            ->where('editorial_status', 'published')->with('answers')->orderBy('id')->first());

        $this->assertNotNull($revision, 'Erwartet ein Thema mit "&" im Katalog (z. B. "Lichter, Zeichen & Schallsignale").');
        $topic = $revision->topic;

        $tenant = $this->createTestTenant();
        $learner = $this->createTenantUser($tenant, 'learner');
        // SBF-BIN-MOTOR bindet das Modul SBF_BINNEN ein, dem die "&"-Frage
        // ("Lichter, Zeichen & Schallsignale") gehört.
        $course = $this->existingCourse('SBF-BIN-MOTOR');
        $this->grantEntitlement($tenant, $learner, $course);

        $payload = [
            'revision_id' => $revision->id,
            'mode' => 'topic',
        ];

        if ($revision->question_type === 'multi_choice') {
            $payload['answer_ids'] = [$revision->answers->firstWhere('is_correct', true)->id];
        } elseif ($revision->question_type === 'open_text') {
            $payload['known'] = true;
        } else {
            $payload['answer_id'] = $revision->answers->firstWhere('is_correct', true)->id;
        }

        // topic kommt -- wie beim echten Formular (action="...&topic=...") --
        // über die Query-String der Action-URL, nicht aus dem Body.
        $response = $this->actingAsInTenant($learner, $tenant)
            ->followingRedirects()
            ->post('/courses/'.$course->id.'/learn/attempts?topic='.urlencode($topic), $payload);

        $response->assertOk();

        preg_match_all('/href="([^"]*\/learn\?[^"]*)"/', $response->getContent(), $matches);
        $nextLink = collect($matches[1])->first(fn ($href) => str_contains($href, 'mode=topic'));

        $this->assertNotNull($nextLink, 'Kein "Nächste Frage"-Link mit mode=topic gefunden.');

        $query = [];
        parse_str(html_entity_decode(parse_url($nextLink, PHP_URL_QUERY)), $query);

        $this->assertSame($topic, $query['topic'] ?? null);
    }
}
