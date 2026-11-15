<?php

namespace Tests\Feature\Api;

use App\Models\Module;
use App\Models\PraxisTask;
use App\Support\QuestionMedia;

/** Mobile-API: geschützte Fragenbilder kommen als signierte, kurzlebige `url`. */
class QuestionMediaApiTest extends MobileApiTestCase
{
    public function test_trainer_question_carries_a_signed_short_lived_image_url(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        [$question, $asset] = $this->onAdmin(function () {
            $question = Module::where('code', 'SBF_BASIS')->firstOrFail()->questions()->where('official_number', '4')->firstOrFail();

            return [$question, $question->publishedRevision()->media()->firstOrFail()];
        });
        $token = $this->apiLogin($user);

        $next = $this->api('GET', "/courses/{$course->id}/trainer/next?question_ids={$question->id}", $token, $tenant)->assertOk();
        $this->assertSame($question->id, $next->json('data.question.id'));
        $media = $next->json('data.question.prompt_media.0');
        $this->assertSame('image', $media['kind']);
        $this->assertTrue($media['available']);
        $this->assertMatchesRegularExpression('#^https?://[^/]+/media/question-media/'.$asset->id.'\?.*expires=\d+.*signature=#', $media['url']);
        $this->assertStringNotContainsString('/storage/', $media['url']);

        // Ohne Token ladbar (Bild-Loader), aber nur kurz gültig.
        $this->get($media['url'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->travel(QuestionMedia::URL_MINUTES + 1)->minutes();
        $this->get($media['url'])->assertForbidden();
        $this->travelBack();

        // Eine neue Antwort enthält eine frische Adresse.
        $again = $this->api('GET', "/courses/{$course->id}/trainer/next?question_ids={$question->id}", $token, $tenant)->assertOk();
        $this->get($again->json('data.question.prompt_media.0.url'))->assertOk();
    }

    public function test_praxis_cards_carry_signed_image_urls(): void
    {
        $tenant = $this->createTestTenant();
        $user = $this->createTenantUser($tenant, 'learner');
        $course = $this->existingCourse('SBF-SEE');
        $this->grantEntitlement($tenant, $user, $course);
        $task = $this->onAdmin(fn () => PraxisTask::with('media')->where('course_id', $course->id)->where('active', true)
            ->whereHas('media', fn ($q) => $q->where('source', 'SBF See Praxistrainer'))->firstOrFail());
        $token = $this->apiLogin($user);

        $cards = collect($this->api('GET', "/courses/{$course->id}/praxis", $token, $tenant)->assertOk()->json('data'))->flatMap(fn ($c) => $c['cards']);
        $url = $cards->firstWhere('id', $task->id)['media'][0]['url'];
        $this->assertMatchesRegularExpression('#/media/question-media/'.$task->media->id.'\?.*signature=#', $url);
        $this->get($url)->assertOk();
    }
}
